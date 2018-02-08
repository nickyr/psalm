<?php
namespace Psalm\Checker;

use PhpParser;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Codebase\CallMap;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\EffectsAnalyser;
use Psalm\FileManipulation\FunctionDocblockManipulator;
use Psalm\FunctionLikeParameter;
use Psalm\Issue\ImplementedReturnTypeMismatch;
use Psalm\Issue\InvalidFalsableReturnType;
use Psalm\Issue\InvalidNullableReturnType;
use Psalm\Issue\InvalidParamDefault;
use Psalm\Issue\InvalidReturnType;
use Psalm\Issue\InvalidToString;
use Psalm\Issue\LessSpecificReturnType;
use Psalm\Issue\MethodSignatureMismatch;
use Psalm\Issue\MismatchingDocblockParamType;
use Psalm\Issue\MismatchingDocblockReturnType;
use Psalm\Issue\MissingClosureParamType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MissingParamType;
use Psalm\Issue\MissingReturnType;
use Psalm\Issue\MixedInferredReturnType;
use Psalm\Issue\MoreSpecificImplementedParamType;
use Psalm\Issue\MoreSpecificImplementedReturnType;
use Psalm\Issue\MoreSpecificReturnType;
use Psalm\Issue\OverriddenMethodAccess;
use Psalm\Issue\ReservedWord;
use Psalm\Issue\UnusedParam;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

abstract class FunctionLikeChecker extends SourceChecker implements StatementsSource
{
    /**
     * @var Closure|Function_|ClassMethod
     */
    protected $function;

    /**
     * @var array<string>
     */
    protected $suppressed_issues;

    /**
     * @var bool
     */
    protected $is_static = false;

    /**
     * @var StatementsSource
     */
    protected $source;

    /**
     * @var FileChecker
     */
    public $file_checker;

    /**
     * @var array<string, array<string, Type\Union>>
     */
    protected $return_vars_in_scope = [];

    /**
     * @var array<string, array<string, bool>>
     */
    protected $return_vars_possibly_in_scope = [];

    /**
     * @var Type\Union|null
     */
    private $local_return_type;

    /**
     * @var array<string, array>
     */
    protected static $no_effects_hashes = [];

    /**
     * @param Closure|Function_|ClassMethod $function
     * @param StatementsSource $source
     */
    public function __construct($function, StatementsSource $source)
    {
        $this->function = $function;
        $this->source = $source;
        $this->file_checker = $source->getFileChecker();
        $this->suppressed_issues = $source->getSuppressedIssues();
    }

    /**
     * @param Context       $context
     * @param Context|null  $global_context
     * @param bool          $add_mutations  whether or not to add mutations to this method
     *
     * @return false|null
     */
    public function analyze(Context $context, Context $global_context = null, $add_mutations = false)
    {
        /** @var array<PhpParser\Node\Expr|PhpParser\Node\Stmt> */
        $function_stmts = $this->function->getStmts() ?: [];

        $hash = null;

        $cased_method_id = null;

        $class_storage = null;

        if ($global_context) {
            foreach ($global_context->constants as $const_name => $var_type) {
                if (!$context->hasVariable($const_name)) {
                    $context->vars_in_scope[$const_name] = clone $var_type;
                }
            }
        }

        $project_checker = $this->file_checker->project_checker;

        $file_storage_provider = $project_checker->file_storage_provider;

        $implemented_docblock_param_types = [];

        $project_checker = $this->file_checker->project_checker;
        $codebase = $project_checker->codebase;

        $classlike_storage_provider = $project_checker->classlike_storage_provider;

        if ($this->function instanceof ClassMethod) {
            $real_method_id = (string)$this->getMethodId();

            $method_id = (string)$this->getMethodId($context->self);

            if ($add_mutations) {
                $hash = $real_method_id . json_encode([
                    $context->vars_in_scope,
                        $context->vars_possibly_in_scope,
                    ]);

                // if we know that the function has no effects on vars, we don't bother rechecking
                if (isset(self::$no_effects_hashes[$hash])) {
                    list(
                        $context->vars_in_scope,
                        $context->vars_possibly_in_scope
                    ) = self::$no_effects_hashes[$hash];

                    return null;
                }
            } elseif ($context->self) {
                $context->vars_in_scope['$this'] = new Type\Union([new TNamedObject($context->self)]);
                $context->vars_possibly_in_scope['$this'] = true;
            }

            $fq_class_name = (string)$context->self;

            $class_storage = $classlike_storage_provider->get($fq_class_name);

            try {
                $storage = $codebase->methods->getStorage($real_method_id);
            } catch (\UnexpectedValueException $e) {
                if (!$class_storage->parent_classes) {
                    throw $e;
                }

                $declaring_method_id = (string) $codebase->methods->getDeclaringMethodId($method_id);

                // happens for fake constructors
                $storage = $codebase->methods->getStorage($declaring_method_id);
            }

            $cased_method_id = $fq_class_name . '::' . $storage->cased_name;

            $overridden_method_ids = $codebase->methods->getOverriddenMethodIds($method_id);

            if ($this->function->name === '__construct') {
                $context->inside_constructor = true;
            }

            if ($overridden_method_ids && $this->function->name !== '__construct') {
                foreach ($overridden_method_ids as $overridden_method_id) {
                    $parent_method_storage = $codebase->methods->getStorage($overridden_method_id);

                    list($overridden_fq_class_name) = explode('::', $overridden_method_id);

                    $parent_storage = $classlike_storage_provider->get($overridden_fq_class_name);

                    self::compareMethods(
                        $project_checker,
                        $class_storage,
                        $parent_storage,
                        $storage,
                        $parent_method_storage,
                        new CodeLocation(
                            $this,
                            $this->function,
                            null,
                            true
                        ),
                        $storage->suppressed_issues
                    );

                    foreach ($parent_method_storage->params as $i => $guide_param) {
                        if ($guide_param->type && (!$guide_param->signature_type || !$class_storage->user_defined)) {
                            $implemented_docblock_param_types[$i] = true;
                        }
                    }
                }
            }
        } elseif ($this->function instanceof Function_) {
            $file_storage = $file_storage_provider->get($this->source->getFilePath());

            $storage = $file_storage->functions[(string)$this->getMethodId()];

            $cased_method_id = $this->function->name;
        } else { // Closure
            $function_id = $this->getMethodId();

            $storage = $codebase->getClosureStorage($this->source->getFilePath(), $function_id);

            if ($storage->return_type) {
                $closure_return_type = ExpressionChecker::fleshOutType(
                    $project_checker,
                    $storage->return_type,
                    $context->self,
                    $context->self
                );
            } else {
                $closure_return_type = Type::getMixed();
            }

            /** @var PhpParser\Node\Expr\Closure $this->function */
            $this->function->inferredType = new Type\Union([
                new Type\Atomic\Fn(
                    'Closure',
                    $storage->params,
                    $closure_return_type
                ),
            ]);
        }

        $this->suppressed_issues = array_merge(
            $this->getSource()->getSuppressedIssues(),
            $storage->suppressed_issues
        );

        if ($storage instanceof MethodStorage && $storage->is_static) {
            $this->is_static = true;
        }

        $statements_checker = new StatementsChecker($this);

        $template_types = $storage->template_types;

        if ($class_storage && $class_storage->template_types) {
            $template_types = array_merge($template_types ?: [], $class_storage->template_types);
        }

        foreach ($storage->params as $offset => $function_param) {
            $signature_type = $function_param->signature_type;

            if ($function_param->type) {
                $param_type = clone $function_param->type;

                $param_type = ExpressionChecker::fleshOutType(
                    $project_checker,
                    $param_type,
                    $context->self,
                    $context->self
                );
            } else {
                $param_type = Type::getMixed();
            }

            $context->vars_in_scope['$' . $function_param->name] = $param_type;
            $context->vars_possibly_in_scope['$' . $function_param->name] = true;

            if ($context->collect_references && $function_param->location) {
                $context->unreferenced_vars['$' . $function_param->name] = $function_param->location;
            }

            if (!$function_param->type_location || !$function_param->location) {
                continue;
            }

            /**
             * @psalm-suppress MixedArrayAccess
             *
             * @var PhpParser\Node\Param
             */
            $parser_param = $this->function->getParams()[$offset];

            if ($signature_type) {
                if (!TypeChecker::isContainedBy(
                    $codebase,
                    $param_type,
                    $signature_type
                )
                ) {
                    if ($project_checker->alter_code
                        && isset($project_checker->getIssuesToFix()['MismatchingDocblockParamType'])
                    ) {
                        $this->addOrUpdateParamType($project_checker, $function_param->name, $signature_type, true);

                        continue;
                    }

                    if (IssueBuffer::accepts(
                        new MismatchingDocblockParamType(
                            'Parameter $' . $function_param->name . ' has wrong type \'' . $param_type .
                                '\', should be \'' . $signature_type . '\'',
                            $function_param->type_location
                        ),
                        $storage->suppressed_issues
                    )) {
                        return false;
                    }

                    $signature_type->check(
                        $this,
                        $function_param->type_location,
                        $storage->suppressed_issues,
                        [],
                        false
                    );

                    continue;
                }
            }

            if ($parser_param->default) {
                $default_type = StatementsChecker::getSimpleType($parser_param->default);

                if ($default_type &&
                    !TypeChecker::isContainedBy(
                        $codebase,
                        $default_type,
                        $param_type
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidParamDefault(
                            'Default value for argument ' . ($offset + 1) . ' of method ' . $cased_method_id .
                                ' does not match the given type ' . $param_type,
                            $function_param->type_location
                        )
                    )) {
                        // fall through
                    }
                }
            }

            if ($template_types) {
                $substituted_type = clone $param_type;
                $generic_types = [];
                $substituted_type->replaceTemplateTypesWithStandins($template_types, $generic_types, null);
                $substituted_type->check(
                    $this->source,
                    $function_param->type_location,
                    $this->suppressed_issues,
                    [],
                    false
                );
            } else {
                if ($param_type->isVoid()) {
                    if (IssueBuffer::accepts(
                        new ReservedWord(
                            'Parameter cannot be void',
                            $function_param->type_location
                        ),
                        $this->suppressed_issues
                    )) {
                        // fall through
                    }
                }

                $param_type->check(
                    $this->source,
                    $function_param->type_location,
                    $this->suppressed_issues,
                    [],
                    false
                );
            }

            if ($codebase->collect_references) {
                if ($function_param->type_location !== $function_param->signature_type_location &&
                    $function_param->signature_type_location &&
                    $function_param->signature_type
                ) {
                    $function_param->signature_type->check(
                        $this->source,
                        $function_param->signature_type_location,
                        $this->suppressed_issues,
                        [],
                        false
                    );
                }
            }

            if ($function_param->by_ref) {
                $context->byref_constraints['$' . $function_param->name]
                    = new \Psalm\ReferenceConstraint(!$param_type->isMixed() ? $param_type : null);
            }

            if ($function_param->by_ref) {
                // register by ref params as having been used, to avoid false positives
                // @todo change the assignment analysis *just* for byref params
                // so that we don't have to do this
                $context->hasVariable('$' . $function_param->name);
            }

            $statements_checker->registerVariable(
                '$' . $function_param->name,
                $function_param->location,
                null
            );
        }

        if ($storage->return_type && $storage->return_type_location && !$storage->has_template_return_type) {
            if (!$storage->signature_return_type || $storage->signature_return_type === $storage->return_type) {
                $fleshed_out_return_type = ExpressionChecker::fleshOutType(
                    $project_checker,
                    $storage->return_type,
                    $context->self,
                    $context->self
                );

                $fleshed_out_return_type->check(
                    $this,
                    $storage->return_type_location,
                    $storage->suppressed_issues,
                    [],
                    false
                );
            } else {
                $fleshed_out_signature_type = ExpressionChecker::fleshOutType(
                    $project_checker,
                    $storage->signature_return_type,
                    $context->self,
                    $context->self
                );

                $fleshed_out_signature_type->check(
                    $this,
                    $storage->signature_return_type_location ?: $storage->return_type_location,
                    $storage->suppressed_issues,
                    [],
                    false
                );

                if (!$this->function instanceof Closure) {
                    $fleshed_out_return_type = ExpressionChecker::fleshOutType(
                        $project_checker,
                        $storage->return_type,
                        $context->self,
                        $context->self
                    );

                    $fleshed_out_signature_type = ExpressionChecker::fleshOutType(
                        $project_checker,
                        $storage->signature_return_type,
                        $context->self,
                        $context->self
                    );

                    if (!TypeChecker::isContainedBy(
                        $codebase,
                        $fleshed_out_return_type,
                        $fleshed_out_signature_type
                    )
                    ) {
                        if ($project_checker->alter_code
                            && isset($project_checker->getIssuesToFix()['MismatchingDocblockReturnType'])
                        ) {
                            $this->addOrUpdateReturnType($project_checker, $storage->signature_return_type);

                            return null;
                        }

                        if (IssueBuffer::accepts(
                            new MismatchingDocblockReturnType(
                                'Docblock has incorrect return type \'' . $storage->return_type .
                                    '\', should be \'' . $storage->signature_return_type . '\'',
                                $storage->return_type_location
                            ),
                            $storage->suppressed_issues
                        )) {
                            return false;
                        }
                    }
                }
            }
        }

        $statements_checker->analyze($function_stmts, $context, null, $global_context, true);

        foreach ($storage->params as $offset => $function_param) {
            // only complain if there's no type defined by a parent type
            if (!$function_param->type
                && $function_param->location
                && !isset($implemented_docblock_param_types[$offset])
            ) {
                $possible_type = null;

                if (isset($context->possible_param_types[$function_param->name])) {
                    $possible_type = $context->possible_param_types[$function_param->name];
                }

                $infer_text = $project_checker->infer_types_from_usage
                    ? ', ' . ($possible_type ? 'should be ' . $possible_type : 'could not infer type')
                    : '';

                if ($this->function instanceof Closure) {
                    IssueBuffer::accepts(
                        new MissingClosureParamType(
                            'Parameter $' . $function_param->name . ' has no provided type' . $infer_text,
                            $function_param->location
                        ),
                        $storage->suppressed_issues
                    );
                } else {
                    IssueBuffer::accepts(
                        new MissingParamType(
                            'Parameter $' . $function_param->name . ' has no provided type' . $infer_text,
                            $function_param->location
                        ),
                        $storage->suppressed_issues
                    );
                }
            }
        }

        if ($this->function instanceof Closure) {
            $this->verifyReturnType(
                $project_checker,
                $storage->return_type,
                $this->source->getFQCLN(),
                $storage->return_type_location
            );

            if (!$storage->return_type || $storage->return_type->isMixed()) {
                $closure_yield_types = [];
                $closure_return_types = EffectsAnalyser::getReturnTypes(
                    $this->function->stmts,
                    $closure_yield_types,
                    $ignore_nullable_issues,
                    $ignore_falsable_issues,
                    true
                );

                if ($closure_return_types && $this->function->inferredType) {
                    /** @var Type\Atomic\Fn */
                    $closure_atomic = $this->function->inferredType->getTypes()['Closure'];
                    $closure_atomic->return_type = new Type\Union($closure_return_types);
                }
            }
        }

        if ($context->collect_references
            && !$project_checker->find_references_to
            && $context->check_variables
        ) {
            foreach ($statements_checker->getUnusedVarLocations() as list($var_name, $original_location)) {
                if (!array_key_exists(substr($var_name, 1), $storage->param_types)) {
                    continue;
                }

                $position = array_search(substr($var_name, 1), array_keys($storage->param_types), true);

                if ($position === false) {
                    throw new \UnexpectedValueException('$position should not be false here');
                }

                if ($storage->params[$position]->by_ref) {
                    continue;
                }

                if (!($storage instanceof MethodStorage)
                    || $storage->visibility === ClassLikeChecker::VISIBILITY_PRIVATE
                ) {
                    if (IssueBuffer::accepts(
                        new UnusedParam(
                            'Param ' . $var_name . ' is never referenced in this method',
                            $original_location
                        ),
                        $this->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    $fq_class_name = (string)$context->self;

                    $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);

                    $method_name_lc = strtolower($storage->cased_name);

                    if ($storage->abstract || !isset($class_storage->overridden_method_ids[$method_name_lc])) {
                        continue;
                    }

                    $parent_method_id = end($class_storage->overridden_method_ids[$method_name_lc]);

                    if ($parent_method_id) {
                        $parent_method_storage = $codebase->methods->getStorage($parent_method_id);

                        // if the parent method has a param at that position and isn't abstract
                        if (!$parent_method_storage->abstract
                            && isset($parent_method_storage->params[$position])
                        ) {
                            continue;
                        }
                    }

                    $storage->unused_params[$position] = $original_location;
                }
            }

            if ($storage instanceof MethodStorage && $class_storage) {
                foreach ($storage->params as $i => $_) {
                    if (!isset($storage->unused_params[$i])) {
                        $storage->used_params[$i] = true;

                        /** @var ClassMethod $this->function */
                        $method_name_lc = strtolower($storage->cased_name);

                        if (!isset($class_storage->overridden_method_ids[$method_name_lc])) {
                            continue;
                        }

                        foreach ($class_storage->overridden_method_ids[$method_name_lc] as $parent_method_id) {
                            $parent_method_storage = $codebase->methods->getStorage($parent_method_id);

                            $parent_method_storage->used_params[$i] = true;
                        }
                    }
                }
            }
        }

        if ($add_mutations) {
            if (isset($this->return_vars_in_scope[''])) {
                $context->vars_in_scope = TypeChecker::combineKeyedTypes(
                    $context->vars_in_scope,
                    $this->return_vars_in_scope['']
                );
            }

            if (isset($this->return_vars_possibly_in_scope[''])) {
                $context->vars_possibly_in_scope = array_merge(
                    $context->vars_possibly_in_scope,
                    $this->return_vars_possibly_in_scope['']
                );
            }

            foreach ($context->vars_in_scope as $var => $_) {
                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                    unset($context->vars_in_scope[$var]);
                }
            }

            foreach ($context->vars_possibly_in_scope as $var => $_) {
                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                    unset($context->vars_possibly_in_scope[$var]);
                }
            }

            if ($hash && $this instanceof MethodChecker) {
                self::$no_effects_hashes[$hash] = [
                    $context->vars_in_scope,
                    $context->vars_possibly_in_scope,
                ];
            }
        }

        return null;
    }

    /**
     * @param  ProjectChecker   $project_checker
     * @param  ClassLikeStorage $implementer_classlike_storage
     * @param  ClassLikeStorage $guide_classlike_storage
     * @param  MethodStorage    $implementer_method_storage
     * @param  MethodStorage    $guide_method_storage
     * @param  CodeLocation     $code_location
     * @param  array            $suppressed_issues
     *
     * @return false|null
     */
    public static function compareMethods(
        ProjectChecker $project_checker,
        ClassLikeStorage $implementer_classlike_storage,
        ClassLikeStorage $guide_classlike_storage,
        MethodStorage $implementer_method_storage,
        MethodStorage $guide_method_storage,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $codebase = $project_checker->codebase;

        $implementer_method_id = $implementer_classlike_storage->name . '::'
            . strtolower($guide_method_storage->cased_name);

        $implementer_declaring_method_id = $codebase->methods->getDeclaringMethodId($implementer_method_id);

        $cased_implementer_method_id = $implementer_classlike_storage->name . '::'
            . $implementer_method_storage->cased_name;

        $cased_guide_method_id = $guide_classlike_storage->name . '::' . $guide_method_storage->cased_name;

        if ($implementer_method_storage->visibility > $guide_method_storage->visibility) {
            if (IssueBuffer::accepts(
                new OverriddenMethodAccess(
                    'Method ' . $cased_implementer_method_id . ' has different access level than '
                        . $cased_guide_method_id,
                    $code_location
                )
            )) {
                return false;
            }

            return null;
        }

        if ($guide_method_storage->signature_return_type) {
            $guide_signature_return_type = ExpressionChecker::fleshOutType(
                $project_checker,
                $guide_method_storage->signature_return_type,
                $guide_classlike_storage->name,
                $guide_classlike_storage->name
            );

            $implementer_signature_return_type = $implementer_method_storage->signature_return_type
                ? ExpressionChecker::fleshOutType(
                    $project_checker,
                    $implementer_method_storage->signature_return_type,
                    $implementer_classlike_storage->name,
                    $implementer_classlike_storage->name
                ) : null;

            if (!TypeChecker::isContainedByInPhp($implementer_signature_return_type, $guide_signature_return_type)) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Method ' . $cased_implementer_method_id . ' with return type \''
                            . $implementer_signature_return_type . '\' is different to return type \''
                            . $guide_signature_return_type . '\' of inherited method ' . $cased_guide_method_id,
                        $code_location
                    )
                )) {
                    return false;
                }

                return null;
            }
        } elseif ($guide_method_storage->return_type
            && $implementer_method_storage->return_type
            && $implementer_classlike_storage->user_defined
            && !$guide_classlike_storage->stubbed
        ) {
            $implementer_method_storage_return_type = ExpressionChecker::fleshOutType(
                $project_checker,
                $implementer_method_storage->return_type,
                $implementer_classlike_storage->name,
                $implementer_classlike_storage->name
            );

            $guide_method_storage_return_type = ExpressionChecker::fleshOutType(
                $project_checker,
                $guide_method_storage->return_type,
                $guide_classlike_storage->name,
                $guide_classlike_storage->name
            );

            // treat void as null when comparing against docblock implementer
            if ($implementer_method_storage_return_type->isVoid()) {
                $implementer_method_storage_return_type = Type::getNull();
            }

            if ($guide_method_storage_return_type->isVoid()) {
                $guide_method_storage_return_type = Type::getNull();
            }

            if (!TypeChecker::isContainedBy(
                $codebase,
                $implementer_method_storage_return_type,
                $guide_method_storage_return_type,
                false,
                false,
                $has_scalar_match,
                $type_coerced,
                $type_coerced_from_mixed
            )) {
                // is the declared return type more specific than the inferred one?
                if ($type_coerced) {
                    if (IssueBuffer::accepts(
                        new MoreSpecificImplementedReturnType(
                            'The return type \'' . $guide_method_storage->return_type
                            . '\' for ' . $cased_guide_method_id . ' is more specific than the implemented '
                            . 'return type for ' . $implementer_declaring_method_id . ' \''
                            . $implementer_method_storage->return_type . '\'',
                            $implementer_method_storage->location ?: $code_location
                        ),
                        $suppressed_issues
                    )) {
                        return false;
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new ImplementedReturnTypeMismatch(
                            'The return type \'' . $guide_method_storage->return_type
                            . '\' for ' . $cased_guide_method_id . ' is different to the implemented '
                            . 'return type for ' . $implementer_declaring_method_id . ' \''
                            . $implementer_method_storage->return_type . '\'',
                            $implementer_method_storage->location ?: $code_location
                        ),
                        $suppressed_issues
                    )) {
                        return false;
                    }
                }
            }
        }

        foreach ($guide_method_storage->params as $i => $guide_param) {
            if (!isset($implementer_method_storage->params[$i])) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Method ' . $cased_implementer_method_id . ' has fewer arguments than parent method ' .
                            $cased_guide_method_id,
                        $code_location
                    )
                )) {
                    return false;
                }

                return null;
            }

            $implementer_param = $implementer_method_storage->params[$i];

            if ($guide_classlike_storage->user_defined
                && $implementer_param->signature_type
                && !TypeChecker::isContainedByInPhp($guide_param->signature_type, $implementer_param->signature_type)
            ) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' .
                            $implementer_param->signature_type . '\', expecting \'' .
                            $guide_param->signature_type . '\' as defined by ' .
                            $cased_guide_method_id,
                        $implementer_method_storage->params[$i]->location
                            ?: $code_location
                    )
                )) {
                    return false;
                }

                return null;
            }

            if ($guide_classlike_storage->user_defined
                && $implementer_param->type
                && $guide_param->type
                && $implementer_param->type->getId() !== $guide_param->type->getId()
            ) {
                if (!TypeChecker::isContainedBy(
                    $codebase,
                    $guide_param->type,
                    $implementer_param->type,
                    false,
                    false
                )) {
                    if (IssueBuffer::accepts(
                        new MoreSpecificImplementedParamType(
                            'Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' .
                                $implementer_param->type . '\', expecting \'' .
                                $guide_param->type . '\' as defined by ' .
                                $cased_guide_method_id,
                            $implementer_method_storage->params[$i]->location
                                ?: $code_location
                        ),
                        $suppressed_issues
                    )) {
                        return false;
                    }
                }
            }

            if ($guide_classlike_storage->user_defined && $implementer_param->by_ref !== $guide_param->by_ref) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' is' .
                            ($implementer_param->by_ref ? '' : ' not') . ' passed by reference, but argument ' .
                            ($i + 1) . ' of ' . $cased_guide_method_id . ' is' . ($guide_param->by_ref ? '' : ' not'),
                        $implementer_method_storage->params[$i]->location
                            ?: $code_location
                    )
                )) {
                    return false;
                }

                return null;
            }

            $implemeneter_param_type = $implementer_method_storage->params[$i]->type;

            $or_null_guide_type = $guide_param->signature_type
                ? clone $guide_param->signature_type
                : null;

            if ($or_null_guide_type) {
                $or_null_guide_type->addType(new Type\Atomic\TNull);
            }

            if (!$guide_classlike_storage->user_defined
                && $guide_param->type
                && !$guide_param->type->isMixed()
                && !$guide_param->type->from_docblock
                && (
                    !$implemeneter_param_type
                    || (
                        $implemeneter_param_type->getId() !== $guide_param->type->getId()
                        && (
                            !$or_null_guide_type
                            || $implemeneter_param_type->getId() !== $or_null_guide_type->getId()
                        )
                    )
                )
            ) {
                if (IssueBuffer::accepts(
                    new MethodSignatureMismatch(
                        'Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' .
                            $implementer_method_storage->params[$i]->type . '\', expecting \'' .
                            $guide_param->type . '\' as defined by ' .
                            $cased_guide_method_id,
                        $implementer_method_storage->params[$i]->location
                            ?: $code_location
                    )
                )) {
                    return false;
                }

                return null;
            }
        }

        if ($guide_classlike_storage->user_defined
            && $implementer_method_storage->cased_name !== '__construct'
            && $implementer_method_storage->required_param_count > $guide_method_storage->required_param_count
        ) {
            if (IssueBuffer::accepts(
                new MethodSignatureMismatch(
                    'Method ' . $cased_implementer_method_id . ' has more arguments than parent method ' .
                        $cased_guide_method_id,
                    $code_location
                )
            )) {
                return false;
            }

            return null;
        }
    }

    /**
     * Adds return types for the given function
     *
     * @param   string  $return_type
     * @param   Context $context
     *
     * @return  void
     */
    public function addReturnTypes($return_type, Context $context)
    {
        if (isset($this->return_vars_in_scope[$return_type])) {
            $this->return_vars_in_scope[$return_type] = TypeChecker::combineKeyedTypes(
                $context->vars_in_scope,
                $this->return_vars_in_scope[$return_type]
            );
        } else {
            $this->return_vars_in_scope[$return_type] = $context->vars_in_scope;
        }

        if (isset($this->return_vars_possibly_in_scope[$return_type])) {
            $this->return_vars_possibly_in_scope[$return_type] = array_merge(
                $context->vars_possibly_in_scope,
                $this->return_vars_possibly_in_scope[$return_type]
            );
        } else {
            $this->return_vars_possibly_in_scope[$return_type] = $context->vars_possibly_in_scope;
        }
    }

    /**
     * @return null|string
     */
    public function getMethodName()
    {
        if ($this->function instanceof ClassMethod) {
            return (string)$this->function->name;
        }
    }

    /**
     * @param string|null $context_self
     *
     * @return string
     */
    public function getMethodId($context_self = null)
    {
        if ($this->function instanceof ClassMethod) {
            $function_name = (string)$this->function->name;

            return ($context_self ?: $this->source->getFQCLN()) . '::' . strtolower($function_name);
        }

        if ($this->function instanceof Function_) {
            $namespace = $this->source->getNamespace();

            return ($namespace ? strtolower($namespace) . '\\' : '') . strtolower($this->function->name);
        }

        return $this->getFilePath() . ':' . $this->function->getLine() . ':-:closure';
    }

    /**
     * @param string|null $context_self
     *
     * @return string
     */
    public function getCorrectlyCasedMethodId($context_self = null)
    {
        if ($this->function instanceof ClassMethod) {
            $function_name = (string)$this->function->name;

            return ($context_self ?: $this->source->getFQCLN()) . '::' . $function_name;
        }

        if ($this->function instanceof Function_) {
            $namespace = $this->source->getNamespace();

            return ($namespace ? $namespace . '\\' : '') . $this->function->name;
        }

        return $this->getFilePath() . ':' . $this->function->getLine() . ':-:closure';
    }

    /**
     * @return FunctionLikeStorage
     */
    public function getFunctionLikeStorage(StatementsChecker $statements_checker)
    {
        $project_checker = $this->getFileChecker()->project_checker;
        $codebase = $project_checker->codebase;

        if ($this->function instanceof ClassMethod) {
            $method_id = (string) $this->getMethodId();
            $codebase_methods = $codebase->methods;

            try {
                return $codebase_methods->getStorage($method_id);
            } catch (\UnexpectedValueException $e) {
                $declaring_method_id = (string) $codebase_methods->getDeclaringMethodId($method_id);

                // happens for fake constructors
                return $codebase_methods->getStorage($declaring_method_id);
            }
        }

        return $codebase->functions->getStorage($statements_checker, (string) $this->getMethodId());
    }

    /**
     * @return array<string, string>
     */
    public function getAliasedClassesFlipped()
    {
        if ($this->source instanceof NamespaceChecker ||
            $this->source instanceof FileChecker ||
            $this->source instanceof ClassLikeChecker
        ) {
            return $this->source->getAliasedClassesFlipped();
        }

        return [];
    }

    /**
     * @return string|null
     */
    public function getFQCLN()
    {
        return $this->source->getFQCLN();
    }

    /**
     * @return null|string
     */
    public function getClassName()
    {
        return $this->source->getClassName();
    }

    /**
     * @return string|null
     */
    public function getParentFQCLN()
    {
        return $this->source->getParentFQCLN();
    }

    /**
     * @return bool
     */
    public function isStatic()
    {
        return $this->is_static;
    }

    /**
     * @return StatementsSource
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @param   Type\Union|null     $return_type
     * @param   string              $fq_class_name
     * @param   CodeLocation|null   $return_type_location
     *
     * @return  false|null
     */
    public function verifyReturnType(
        ProjectChecker $project_checker,
        Type\Union $return_type = null,
        $fq_class_name = null,
        CodeLocation $return_type_location = null
    ) {
        if (!$this->function->getStmts() &&
            (
                $this->function instanceof ClassMethod &&
                ($this->getSource() instanceof InterfaceChecker || $this->function->isAbstract())
            )
        ) {
            return null;
        }

        $is_to_string = $this->function instanceof ClassMethod && strtolower($this->function->name) === '__tostring';

        if ($this->function instanceof ClassMethod &&
            substr($this->function->name, 0, 2) === '__' &&
            !$is_to_string
        ) {
            // do not check __construct, __set, __get, __call etc.
            return null;
        }

        $cased_method_id = $this->getCorrectlyCasedMethodId();

        if (!$return_type_location) {
            $return_type_location = new CodeLocation($this, $this->function, null, true);
        }

        $inferred_yield_types = [];

        /** @var PhpParser\Node\Stmt[] */
        $function_stmts = $this->function->getStmts();

        $inferred_return_type_parts = EffectsAnalyser::getReturnTypes(
            $function_stmts,
            $inferred_yield_types,
            $ignore_nullable_issues,
            $ignore_falsable_issues,
            true
        );

        if ((!$return_type || $return_type->from_docblock)
            && ScopeChecker::getFinalControlActions($function_stmts) !== [ScopeChecker::ACTION_END]
            && !$inferred_yield_types
            && count($inferred_return_type_parts)
        ) {
            // only add null if we have a return statement elsewhere and it wasn't void
            foreach ($inferred_return_type_parts as $inferred_return_type_part) {
                if (!$inferred_return_type_part instanceof Type\Atomic\TVoid) {
                    $atomic_null = new Type\Atomic\TNull();
                    $atomic_null->from_docblock = true;
                    $inferred_return_type_parts[] = $atomic_null;
                    break;
                }
            }
        }

        if ($return_type
            && !$return_type->from_docblock
            && !$return_type->isVoid()
            && !$inferred_yield_types
            && ScopeChecker::getFinalControlActions($function_stmts) !== [ScopeChecker::ACTION_END]
        ) {
            if (IssueBuffer::accepts(
                new InvalidReturnType(
                    'Not all code paths of ' . $cased_method_id . ' end in a return statement, return type '
                        . $return_type . ' expected',
                    $return_type_location
                )
            )) {
                return false;
            }

            return null;
        }

        $inferred_return_type = $inferred_return_type_parts
            ? Type::combineTypes($inferred_return_type_parts)
            : Type::getVoid();
        $inferred_yield_type = $inferred_yield_types ? Type::combineTypes($inferred_yield_types) : null;

        if ($inferred_yield_type) {
            $inferred_return_type = $inferred_yield_type;
        }

        $codebase = $project_checker->codebase;

        if (!$return_type && !$codebase->config->add_void_docblocks && $inferred_return_type->isVoid()) {
            return null;
        }

        $unsafe_return_type = false;

        // prevent any return types that do not return a value from being used in PHP typehints
        if ($project_checker->alter_code
            && $inferred_return_type->isNullable()
            && !$inferred_yield_types
        ) {
            foreach ($inferred_return_type_parts as $inferred_return_type_part) {
                if ($inferred_return_type_part instanceof Type\Atomic\TVoid) {
                    $unsafe_return_type = true;
                }
            }
        }

        $inferred_return_type = TypeChecker::simplifyUnionType(
            $codebase,
            ExpressionChecker::fleshOutType(
                $project_checker,
                $inferred_return_type,
                $this->source->getFQCLN(),
                $this->source->getFQCLN()
            )
        );

        if ($is_to_string) {
            if (!$inferred_return_type->isMixed() && (string)$inferred_return_type !== 'string') {
                if (IssueBuffer::accepts(
                    new InvalidToString(
                        '__toString methods must return a string, ' . $inferred_return_type . ' returned',
                        $return_type_location
                    ),
                    $this->suppressed_issues
                )) {
                    return false;
                }
            }

            return null;
        }

        if (!$return_type) {
            if ($this->function instanceof Closure) {
                if ($project_checker->alter_code
                    && isset($project_checker->getIssuesToFix()['MissingClosureReturnType'])
                ) {
                    if ($inferred_return_type->isMixed() || $inferred_return_type->isNull()) {
                        return null;
                    }

                    $this->addOrUpdateReturnType(
                        $project_checker,
                        $inferred_return_type,
                        ($project_checker->only_replace_php_types_with_non_docblock_types
                            || $unsafe_return_type)
                            && $inferred_return_type->from_docblock
                    );

                    return null;
                }

                if (IssueBuffer::accepts(
                    new MissingClosureReturnType(
                        'Closure does not have a return type, expecting ' . $inferred_return_type,
                        new CodeLocation($this, $this->function, null, true)
                    ),
                    $this->suppressed_issues
                )) {
                    // fall through
                }

                return null;
            }

            if ($project_checker->alter_code
                && isset($project_checker->getIssuesToFix()['MissingReturnType'])
            ) {
                if ($inferred_return_type->isMixed() || $inferred_return_type->isNull()) {
                    return null;
                }

                $this->addOrUpdateReturnType(
                    $project_checker,
                    $inferred_return_type,
                    ($project_checker->only_replace_php_types_with_non_docblock_types
                        || $unsafe_return_type)
                        && $inferred_return_type->from_docblock
                );

                return null;
            }

            if (IssueBuffer::accepts(
                new MissingReturnType(
                    'Method ' . $cased_method_id . ' does not have a return type' .
                      (!$inferred_return_type->isMixed() ? ', expecting ' . $inferred_return_type : ''),
                    new CodeLocation($this, $this->function, null, true)
                ),
                $this->suppressed_issues
            )) {
                // fall through
            }

            return null;
        }

        $self_fq_class_name = $fq_class_name ?: $this->source->getFQCLN();

        // passing it through fleshOutTypes eradicates errant $ vars
        $declared_return_type = ExpressionChecker::fleshOutType(
            $project_checker,
            $return_type,
            $self_fq_class_name,
            $self_fq_class_name
        );

        if (!$inferred_return_type_parts && !$inferred_yield_types) {
            if ($declared_return_type->isVoid()) {
                return null;
            }

            if (ScopeChecker::onlyThrows($function_stmts)) {
                // if there's a single throw statement, it's presumably an exception saying this method is not to be
                // used
                return null;
            }

            if ($project_checker->alter_code && isset($project_checker->getIssuesToFix()['InvalidReturnType'])) {
                $this->addOrUpdateReturnType($project_checker, Type::getVoid());

                return null;
            }

            if (!$declared_return_type->from_docblock || !$declared_return_type->isNullable()) {
                if (IssueBuffer::accepts(
                    new InvalidReturnType(
                        'No return statements were found for method ' . $cased_method_id .
                            ' but return type \'' . $declared_return_type . '\' was expected',
                        $return_type_location
                    )
                )) {
                    return false;
                }
            }

            return null;
        }

        if (!$declared_return_type->isMixed()) {
            if ($inferred_return_type->isVoid() && $declared_return_type->isVoid()) {
                return null;
            }

            if ($inferred_return_type->isMixed() || $inferred_return_type->isEmpty()) {
                if (IssueBuffer::accepts(
                    new MixedInferredReturnType(
                        'Could not verify return type \'' . $declared_return_type . '\' for ' .
                            $cased_method_id,
                        $return_type_location
                    ),
                    $this->suppressed_issues
                )) {
                    return false;
                }

                return null;
            }

            if (!TypeChecker::isContainedBy(
                $codebase,
                $inferred_return_type,
                $declared_return_type,
                true,
                true,
                $has_scalar_match,
                $type_coerced,
                $type_coerced_from_mixed
            )) {
                // is the declared return type more specific than the inferred one?
                if ($type_coerced) {
                    if (IssueBuffer::accepts(
                        new MoreSpecificReturnType(
                            'The declared return type \'' . $declared_return_type . '\' for ' . $cased_method_id .
                                ' is more specific than the inferred return type \'' . $inferred_return_type . '\'',
                            $return_type_location
                        ),
                        $this->suppressed_issues
                    )) {
                        return false;
                    }
                } else {
                    if ($project_checker->alter_code
                        && isset($project_checker->getIssuesToFix()['InvalidReturnType'])
                    ) {
                        $this->addOrUpdateReturnType(
                            $project_checker,
                            $inferred_return_type,
                            ($project_checker->only_replace_php_types_with_non_docblock_types
                                || $unsafe_return_type)
                                && $inferred_return_type->from_docblock
                        );

                        return null;
                    }

                    if (IssueBuffer::accepts(
                        new InvalidReturnType(
                            'The declared return type \'' . $declared_return_type . '\' for ' . $cased_method_id .
                                ' is incorrect, got \'' . $inferred_return_type . '\'',
                            $return_type_location
                        ),
                        $this->suppressed_issues
                    )) {
                        return false;
                    }
                }
            } elseif (!$inferred_return_type->isNullable() && $declared_return_type->isNullable()) {
                if ($project_checker->alter_code
                    && isset($project_checker->getIssuesToFix()['LessSpecificReturnType'])
                ) {
                    $this->addOrUpdateReturnType(
                        $project_checker,
                        $inferred_return_type,
                        ($project_checker->only_replace_php_types_with_non_docblock_types
                            || $unsafe_return_type)
                            && $inferred_return_type->from_docblock
                    );

                    return null;
                }

                if (IssueBuffer::accepts(
                    new LessSpecificReturnType(
                        'The inferred return type \'' . $inferred_return_type . '\' for ' . $cased_method_id .
                            ' is more specific than the declared return type \'' . $declared_return_type . '\'',
                        $return_type_location
                    ),
                    $this->suppressed_issues
                )) {
                    return false;
                }
            }

            if (!$ignore_nullable_issues
                && $inferred_return_type->isNullable()
                && !$declared_return_type->isNullable()
                && !$declared_return_type->isVoid()
            ) {
                if ($project_checker->alter_code
                    && isset($project_checker->getIssuesToFix()['InvalidNullableReturnType'])
                    && !$inferred_return_type->isNull()
                ) {
                    $this->addOrUpdateReturnType(
                        $project_checker,
                        $inferred_return_type,
                        ($project_checker->only_replace_php_types_with_non_docblock_types
                            || $unsafe_return_type)
                            && $inferred_return_type->from_docblock
                    );

                    return null;
                }

                if (IssueBuffer::accepts(
                    new InvalidNullableReturnType(
                        'The declared return type \'' . $declared_return_type . '\' for ' . $cased_method_id .
                            ' is not nullable, but \'' . $inferred_return_type . '\' contains null',
                        $return_type_location
                    ),
                    $this->suppressed_issues
                )) {
                    return false;
                }
            }

            if (!$ignore_falsable_issues
                && $inferred_return_type->isFalsable()
                && !$declared_return_type->isFalsable()
                && !$declared_return_type->hasBool()
            ) {
                if ($project_checker->alter_code
                    && isset($project_checker->getIssuesToFix()['InvalidFalsableReturnType'])
                ) {
                    $this->addOrUpdateReturnType(
                        $project_checker,
                        $inferred_return_type,
                        ($project_checker->only_replace_php_types_with_non_docblock_types
                            || $unsafe_return_type)
                            && $inferred_return_type->from_docblock
                    );

                    return null;
                }

                if (IssueBuffer::accepts(
                    new InvalidFalsableReturnType(
                        'The declared return type \'' . $declared_return_type . '\' for ' . $cased_method_id .
                            ' does not allow false, but \'' . $inferred_return_type . '\' contains false',
                        $return_type_location
                    ),
                    $this->suppressed_issues
                )) {
                    return false;
                }
            }
        }

        return null;
    }

    /**
     * @param string $param_name
     * @param bool $docblock_only
     *
     * @return void
     */
    private function addOrUpdateParamType(
        ProjectChecker $project_checker,
        $param_name,
        Type\Union $inferred_return_type,
        $docblock_only = false
    ) {
        $manipulator = FunctionDocblockManipulator::getForFunction(
            $project_checker,
            $this->source->getFilePath(),
            $this->getMethodId(),
            $this->function
        );
        $manipulator->setParamType(
            $param_name,
            !$docblock_only && $project_checker->php_major_version >= 7
                ? $inferred_return_type->toPhpString(
                    $this->source->getNamespace(),
                    $this->source->getAliasedClassesFlipped(),
                    $this->source->getFQCLN(),
                    $project_checker->php_major_version,
                    $project_checker->php_minor_version
                ) : null,
            $inferred_return_type->toNamespacedString(
                $this->source->getNamespace(),
                $this->source->getAliasedClassesFlipped(),
                $this->source->getFQCLN(),
                false
            ),
            $inferred_return_type->toNamespacedString(
                $this->source->getNamespace(),
                $this->source->getAliasedClassesFlipped(),
                $this->source->getFQCLN(),
                true
            ),
            $inferred_return_type->canBeFullyExpressedInPhp()
        );
    }

    /**
     * @param bool $docblock_only
     *
     * @return void
     */
    private function addOrUpdateReturnType(
        ProjectChecker $project_checker,
        Type\Union $inferred_return_type,
        $docblock_only = false
    ) {
        $manipulator = FunctionDocblockManipulator::getForFunction(
            $project_checker,
            $this->source->getFilePath(),
            $this->getMethodId(),
            $this->function
        );
        $manipulator->setReturnType(
            !$docblock_only && $project_checker->php_major_version >= 7
                ? $inferred_return_type->toPhpString(
                    $this->source->getNamespace(),
                    $this->source->getAliasedClassesFlipped(),
                    $this->source->getFQCLN(),
                    $project_checker->php_major_version,
                    $project_checker->php_minor_version
                ) : null,
            $inferred_return_type->toNamespacedString(
                $this->source->getNamespace(),
                $this->source->getAliasedClassesFlipped(),
                $this->source->getFQCLN(),
                false
            ),
            $inferred_return_type->toNamespacedString(
                $this->source->getNamespace(),
                $this->source->getAliasedClassesFlipped(),
                $this->source->getFQCLN(),
                true
            ),
            $inferred_return_type->canBeFullyExpressedInPhp()
        );
    }

    /**
     * @param  string                           $method_id
     * @param  array<int, PhpParser\Node\Arg>   $args
     *
     * @return array<int, FunctionLikeParameter>
     */
    public static function getMethodParamsById(ProjectChecker $project_checker, $method_id, array $args)
    {
        $fq_class_name = strpos($method_id, '::') !== false ? explode('::', $method_id)[0] : null;

        $codebase = $project_checker->codebase;

        if ($fq_class_name) {
            $class_storage = $project_checker->codebase->classlike_storage_provider->get($fq_class_name);

            if ($class_storage->user_defined || $class_storage->stubbed) {
                $method_params = $codebase->methods->getMethodParams($method_id);

                return $method_params;
            }
        }

        $declaring_method_id = $codebase->methods->getDeclaringMethodId($method_id);

        if (CallMap::inCallMap($declaring_method_id ?: $method_id)) {
            $function_param_options = CallMap::getParamsFromCallMap($declaring_method_id ?: $method_id);

            if ($function_param_options === null) {
                throw new \UnexpectedValueException(
                    'Not expecting $function_param_options to be null for ' . $method_id
                );
            }

            return self::getMatchingParamsFromCallMapOptions($project_checker, $function_param_options, $args);
        }

        return $codebase->methods->getMethodParams($method_id);
    }

    /**
     * @param  string                           $method_id
     * @param  array<int, PhpParser\Node\Arg>   $args
     *
     * @return array<int, FunctionLikeParameter>
     */
    public static function getFunctionParamsFromCallMapById(ProjectChecker $project_checker, $method_id, array $args)
    {
        $function_param_options = CallMap::getParamsFromCallMap($method_id);

        if ($function_param_options === null) {
            throw new \UnexpectedValueException(
                'Not expecting $function_param_options to be null for ' . $method_id
            );
        }

        return self::getMatchingParamsFromCallMapOptions($project_checker, $function_param_options, $args);
    }

    /**
     * @param  array<int, array<int, FunctionLikeParameter>>  $function_param_options
     * @param  array<int, PhpParser\Node\Arg>                 $args
     *
     * @return array<int, FunctionLikeParameter>
     */
    protected static function getMatchingParamsFromCallMapOptions(
        ProjectChecker $project_checker,
        array $function_param_options,
        array $args
    ) {
        if (count($function_param_options) === 1) {
            return $function_param_options[0];
        }

        foreach ($function_param_options as $possible_function_params) {
            $all_args_match = true;

            $last_param = count($possible_function_params)
                ? $possible_function_params[count($possible_function_params) - 1]
                : null;

            $mandatory_param_count = count($possible_function_params);

            foreach ($possible_function_params as $i => $possible_function_param) {
                if ($possible_function_param->is_optional) {
                    $mandatory_param_count = $i;
                    break;
                }
            }

            if ($mandatory_param_count > count($args)) {
                continue;
            }

            foreach ($args as $argument_offset => $arg) {
                if ($argument_offset >= count($possible_function_params)) {
                    if (!$last_param || !$last_param->is_variadic) {
                        $all_args_match = false;
                    }

                    break;
                }

                $param_type = $possible_function_params[$argument_offset]->type;

                if (!$param_type) {
                    continue;
                }

                if (!isset($arg->value->inferredType)) {
                    continue;
                }

                if ($arg->value->inferredType->isMixed()) {
                    continue;
                }

                if (TypeChecker::isContainedBy($project_checker->codebase, $arg->value->inferredType, $param_type)) {
                    continue;
                }

                $all_args_match = false;
                break;
            }

            if ($all_args_match) {
                return $possible_function_params;
            }
        }

        // if we don't succeed in finding a match, set to the first possible and wait for issues below
        return $function_param_options[0];
    }

    /**
     * Get a list of suppressed issues
     *
     * @return array<string>
     */
    public function getSuppressedIssues()
    {
        return $this->suppressed_issues;
    }

    /**
     * @param array<int, string> $new_issues
     *
     * @return void
     */
    public function addSuppressedIssues(array $new_issues)
    {
        $this->suppressed_issues = array_merge($new_issues, $this->suppressed_issues);
    }

    /**
     * @param array<int, string> $new_issues
     *
     * @return void
     */
    public function removeSuppressedIssues(array $new_issues)
    {
        $this->suppressed_issues = array_diff($this->suppressed_issues, $new_issues);
    }

    /**
     * Adds a suppressed issue, useful when creating a method checker from scratch
     *
     * @param string $issue_name
     *
     * @return void
     */
    public function addSuppressedIssue($issue_name)
    {
        $this->suppressed_issues[] = $issue_name;
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        self::$no_effects_hashes = [];
    }

    /**
     * @return FileChecker
     */
    public function getFileChecker()
    {
        return $this->file_checker;
    }

    /**
     * @return Type\Union
     */
    public function getLocalReturnType(Type\Union $storage_return_type)
    {
        if ($this->local_return_type) {
            return $this->local_return_type;
        }

        $this->local_return_type = ExpressionChecker::fleshOutType(
            $this->file_checker->project_checker,
            $storage_return_type,
            $this->getFQCLN(),
            $this->getFQCLN()
        );

        return $this->local_return_type;
    }
}
