<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\AlgebraChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Clause;
use Psalm\Context;
use Psalm\Scope\LoopScope;
use Psalm\Type;

class DoChecker
{
    /**
     * @return void
     */
    public static function analyze(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\Do_ $stmt,
        Context $context
    ) {
        $do_context = clone $context;

        $project_checker = $statements_checker->getFileChecker()->project_checker;

        if ($project_checker->alter_code) {
            $do_context->branch_point = $do_context->branch_point ?: (int) $stmt->getAttribute('startFilePos');
        }

        $loop_scope = new LoopScope($do_context, $context);
        $loop_scope->protected_var_ids = $context->protected_var_ids;

        $suppressed_issues = $statements_checker->getSuppressedIssues();

        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_checker->addSuppressedIssues(['RedundantCondition']);
        }

        $statements_checker->analyze($stmt->stmts, $do_context, $loop_scope);

        if (!in_array('RedundantCondition', $suppressed_issues, true)) {
            $statements_checker->removeSuppressedIssues(['RedundantCondition']);
        }

        foreach ($context->vars_in_scope as $var => $type) {
            if ($type->isMixed()) {
                continue;
            }

            if ($do_context->hasVariable($var)) {
                if ($context->vars_in_scope[$var]->isMixed()) {
                    $do_context->vars_in_scope[$var] = $do_context->vars_in_scope[$var];
                }

                if ($do_context->vars_in_scope[$var]->getId() !== $type->getId()) {
                    $do_context->vars_in_scope[$var] = Type::combineUnionTypes($do_context->vars_in_scope[$var], $type);
                }
            }
        }

        $mixed_var_ids = [];

        foreach ($do_context->vars_in_scope as $var_id => $type) {
            if ($type->isMixed()) {
                $mixed_var_ids[] = $var_id;
            }
        }

        $while_clauses = AlgebraChecker::getFormula(
            $stmt->cond,
            $context->self,
            $statements_checker
        );

        $while_clauses = array_values(
            array_filter(
                $while_clauses,
                /** @return bool */
                function (Clause $c) use ($mixed_var_ids) {
                    $keys = array_keys($c->possibilities);

                    foreach ($keys as $key) {
                        foreach ($mixed_var_ids as $mixed_var_id) {
                            if (preg_match('/^' . preg_quote($mixed_var_id, '/') . '(\[|-)/', $key)) {
                                return false;
                            }
                        }
                    }

                    return true;
                }
            )
        );

        if (!$while_clauses) {
            $while_clauses = [new Clause([], true)];
        }

        $reconcilable_while_types = AlgebraChecker::getTruthsFromFormula($while_clauses);

        if ($reconcilable_while_types) {
            $changed_var_ids = [];
            $while_vars_in_scope_reconciled =
                Type\Reconciler::reconcileKeyedTypes(
                    $reconcilable_while_types,
                    $do_context->vars_in_scope,
                    $changed_var_ids,
                    [],
                    $statements_checker,
                    new \Psalm\CodeLocation($statements_checker->getSource(), $stmt->cond),
                    $statements_checker->getSuppressedIssues()
                );

            $do_context->vars_in_scope = $while_vars_in_scope_reconciled;
        }

        LoopChecker::analyze(
            $statements_checker,
            $stmt->stmts,
            [$stmt->cond],
            [],
            $loop_scope,
            $inner_loop_context,
            true
        );

        foreach ($do_context->vars_in_scope as $var_id => $type) {
            if (!isset($context->vars_in_scope[$var_id])) {
                $context->vars_in_scope[$var_id] = $type;
            }
        }

        // because it's a do {} while, inner loop vars belong to the main context
        if (!$inner_loop_context) {
            throw new \UnexpectedValueException('Should never be null');
        }

        foreach ($inner_loop_context->vars_in_scope as $var_id => $type) {
            if (!isset($context->vars_in_scope[$var_id])) {
                $context->vars_in_scope[$var_id] = $type;
            }
        }

        $context->vars_possibly_in_scope = array_merge(
            $context->vars_possibly_in_scope,
            $do_context->vars_possibly_in_scope
        );

        $context->referenced_var_ids = array_merge(
            $context->referenced_var_ids,
            $do_context->referenced_var_ids
        );

        if ($context->collect_references) {
            $context->unreferenced_vars = $do_context->unreferenced_vars;
        }

        ExpressionChecker::analyze($statements_checker, $stmt->cond, $inner_loop_context);
    }
}
