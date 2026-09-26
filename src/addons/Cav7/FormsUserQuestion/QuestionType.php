<?php

namespace Cav7\FormsUserQuestion;

/**
 * The two forum user question types, as Advanced Forms stores them in
 * xf_snog_forms_questions.type. Pure PHP, so any class can read them without
 * going through XenForo's class extension loader.
 *
 * The strings are permanent once a question uses them. Every such question
 * stores one, so renaming one needs a data migration. They are [a-z0-9_] only
 * because the vendor builds phrase titles from them
 * (snog_forms_question_type_def.<type>), and a phrase title allows nothing else
 * after its dot. The cav7_fuq_ prefix keeps a future vendor type from colliding
 * with them.
 *
 * Registering a type (Snog\Forms\Repository\Question) is not enough to show it
 * on a form. The vendor's snog_forms_question_macros template picks a macro per
 * type in two if/elseif chains, the `question` macro for top-level questions and
 * `conditional_questions` for questions revealed by an earlier answer, and
 * neither chain has an else branch. A type neither chain names renders nothing,
 * not even a label. The template modifications cav7_fuq_question_chain and
 * cav7_fuq_conditional_chain add a branch to each. After an Advanced Forms
 * upgrade, check that both still apply, and that no third chain has appeared.
 * Cav7/Core's cav7-core:check-template-modifications command reports a
 * modification that has stopped applying.
 */
final class QuestionType
{
    /** "Forum user": takes one forum user. */
    public const FORUM_USER = 'cav7_fuq_forum_user';

    /** "Forum users": takes several. */
    public const FORUM_USERS = 'cav7_fuq_forum_users';

    public static function isForumUserType(string $type): bool
    {
        return $type === self::FORUM_USER || $type === self::FORUM_USERS;
    }

    public static function takesSeveral(string $type): bool
    {
        return $type === self::FORUM_USERS;
    }
}
