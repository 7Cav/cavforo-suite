<?php

namespace Cav7\FormsUserQuestion\Snog\Forms\Repository;

use Cav7\FormsUserQuestion\QuestionType;

/**
 * Registers the two forum user question types with Advanced Forms (issue #312).
 *
 * Why these are question types and not a setting on Text questions: the
 * vendor's form copy (Admin\Controller\Forms::actionSave with copyform) copies
 * a question field by field and leaves out type_data, and its export
 * (Entity\Question::getExportData) writes no type_data either. A "this Text
 * question takes forum users" flag would live in type_data, so every copied or
 * moved form would quietly turn back into plain Text. Both paths keep `type`,
 * so a type survives them. Don't fold these back into a flag on Text.
 *
 * What it assumes about the vendor, and what breaks silently if the vendor
 * moves. Checked against [OzzModz] Advanced Forms 2.2.6 RC3:
 *
 *  - getSupportedQuestionTypes() is the one list of question types. Through
 *    getQuestionTypeData() it feeds both the add-question chooser and the
 *    change-type screen, and both refuse a posted type missing from it. The
 *    vendor marks the method "@Deprecated Temporary solution" but offers
 *    nothing in its place. If a release replaces it, both types vanish from
 *    both screens, while questions already converted keep working.
 *  - The chooser lists types in array order, so the two types go straight
 *    after Text rather than at the bottom.
 *  - Each type's title and description come from the phrases
 *    snog_forms_question_type_def.<type> and
 *    snog_forms_question_type_def_desc.<type>, which this addon ships.
 *  - Form copy and export keep `type`, as above. A release that stops copying
 *    it breaks every copied form, not just this addon's questions.
 */
class Question extends XFCP_Question
{
    public function getSupportedQuestionTypes(): array
    {
        $types = parent::getSupportedQuestionTypes();
        $ours = [QuestionType::FORUM_USER, QuestionType::FORUM_USERS];

        $afterText = array_search('text', $types, true);
        if ($afterText === false) {
            return array_merge($types, $ours);
        }

        array_splice($types, $afterText + 1, 0, $ours);

        return $types;
    }
}
