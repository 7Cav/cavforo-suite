<?php

namespace Cav7\FormsUserQuestion\Snog\Forms\Admin\Controller;

use Cav7\FormsUserQuestion\QuestionType;

/**
 * Saves a forum user question's placeholder and default answer from the
 * question editor (issue #315). Every other question type saves exactly as the
 * vendor saves it.
 *
 * The editor's options for the two types come from the admin template
 * modification cav7_fuq_question_type_options, which adds a branch to
 * snog_forms_question_edit_macros::question_types. That branch posts
 * cav7_fuq_placeholder and cav7_fuq_default_answer.
 *
 * What it assumes about the vendor, and what breaks silently if the vendor
 * moves. Checked against [OzzModz] Advanced Forms 2.2.6 RC3:
 *
 *  - questionSaveProcess() maps the editor's fields to columns per type. It
 *    puts placeholder and defanswer into its input only inside a switch on the
 *    posted type, with one case per vendor type and no default. So for a type
 *    the switch doesn't name, both columns keep whatever they held, and
 *    anything the editor posts for them is dropped. This class adds the
 *    mapping for these two types. If a release starts mapping the two columns
 *    for every type, this class still sets them after the vendor does, so its
 *    values win, but check whether it is still needed.
 *  - The same switch is the only place regex, regexerror and expected are
 *    read from their per-type fields. The editor doesn't show them for these
 *    types, so a save blanks them, and type_data too. That is intended. A
 *    save clears a regex or length limit left over from Text.
 *  - questionSaveProcess() returns a FormAction whose basicEntitySave() sets
 *    the input in a setup closure. FormAction runs setup closures in the order
 *    they were added, all before validation, so the closure added here runs
 *    after the vendor's has set `type`, and its values still go through the
 *    entity's own checks, such as the 200-character limit.
 *  - Both editors, for top-level and for conditional questions, post to
 *    actionSave(), and that is the only caller of questionSaveProcess(). The
 *    change-type screen, form copy and import save questions without it.
 *
 * A field that wasn't posted leaves its column alone rather than blanking it.
 * The editor always posts both, so this only matters if the template
 * modification stops applying after a vendor upgrade. The editor then shows
 * "None" for the type, and saving any other setting must not wipe a
 * `{username}` default answer that nobody could see.
 */
class Questions extends XFCP_Questions
{
    /** Editor field => Snog\Forms\Entity\Question column. */
    private const FIELDS = [
        'cav7_fuq_placeholder' => 'placeholder',
        'cav7_fuq_default_answer' => 'defanswer',
    ];

    protected function questionSaveProcess(\Snog\Forms\Entity\Question $question)
    {
        $formAction = parent::questionSaveProcess($question);

        $values = [];
        foreach (self::FIELDS as $field => $column) {
            if ($this->request->exists($field)) {
                $values[$column] = $this->filter($field, 'str');
            }
        }

        $formAction->setup(function () use ($question, $values) {
            if (QuestionType::isForumUserType((string) $question->type)) {
                $question->bulkSet($values);
            }
        });

        return $formAction;
    }
}
