<?php

namespace Cav7\FormsUserQuestion\Snog\Forms\Entity;

use Cav7\FormsUserQuestion\AnswerResolution;
use Cav7\FormsUserQuestion\AnswerResolution\Result;
use Cav7\FormsUserQuestion\QuestionType;
use XF\Finder\UserFinder;

/**
 * Accepts or refuses a forum user question's answer (issue #312). Every other
 * question type passes straight through to the vendor.
 *
 * AnswerResolution holds the rule. This class feeds it the posted answer and a
 * lookup that runs one user finder query per name with `username = ?`, so the
 * column's collation decides the match. It then turns a refusal into the
 * question's error, which blocks the submission.
 *
 * What it assumes about the vendor, and what breaks silently if the vendor
 * moves. Checked against [OzzModz] Advanced Forms 2.2.6 RC3:
 *
 *  - Service\Form\Submit::_validate() calls getAnswerErrors($answer) for each
 *    top-level question, and getAnswerErrors($answer, $parentAnswer) for each
 *    conditional question its parent's answer reveals. $answer is whatever the
 *    form posted under question[<id>], or null when nothing was posted. Any
 *    error it returns blocks the submission. Submit merges the errors with
 *    `+=` on integer keys, so only the first question's error is shown. The
 *    vendor's own types return theirs the same way, and this class matches
 *    them rather than working around it.
 *  - The `error` column is both the required flag and its message. An empty
 *    `error` makes the question optional.
 *  - The parent's getAnswerErrors() is never called for these types, because
 *    it would apply a length limit these types must not have. It reads
 *    type_data minlength and maxlength for every type, and the change-type
 *    screen sets only `type`, so a question converted from Text keeps its old
 *    limits while the editor no longer shows them. Everything else the parent
 *    checks belongs to other types: date bounds, allowed forums, checkbox
 *    counts, the regex (Text, multi-line and number only) and billable rows.
 *    If a release adds a check that should apply to every type, it has to be
 *    copied here.
 *  - The parent's required check refuses a falsy answer for these types, since
 *    they are not in its list of text types. This class keeps that rule and
 *    widens it. XenForo's multiple-mode autocomplete leaves a trailing ", ",
 *    which the request filter trims to ",". That string is truthy, so the
 *    vendor's check would let a required question through with nobody named.
 *    This class refuses a required question whenever its answer names no
 *    forum user.
 *  - Submit reads each answer through getFormattedAnswer() and getTitleAnswer()
 *    about six times per submission, whether or not getAnswerErrors() refused
 *    it. cav7fuqResolve() keeps each answer's result, so those methods can
 *    reuse it instead of querying again.
 */
class Question extends XFCP_Question
{
    /** @var array<string, Result> keyed by the raw answer and whether it takes several */
    protected $cav7fuqResolved = [];

    /**
     * True for "Forum user" and "Forum users". The template modifications on
     * snog_forms_question_macros call it to pick the name box.
     */
    public function isCav7FuqForumUserType(): bool
    {
        return QuestionType::isForumUserType((string) $this->type);
    }

    /**
     * True for "Forum users". cav7_fuq_question_macros::name_box calls it to put
     * the user picker in multiple mode, and in single mode otherwise.
     */
    public function doesCav7FuqTakeSeveral(): bool
    {
        return QuestionType::takesSeveral((string) $this->type);
    }

    public function getAnswerErrors($answer, $conditionalAnswer = '')
    {
        if (!$this->isCav7FuqForumUserType()) {
            return parent::getAnswerErrors($answer, $conditionalAnswer);
        }

        $raw = $this->cav7fuqAnswerText($answer);
        $result = $raw === null ? Result::refused() : $this->cav7fuqResolve($raw);

        if (!$result->isAccepted()) {
            // An optional question has no error text of its own.
            return [$this->error ?: \XF::phrase('cav7_fuq_answer_not_accepted')];
        }

        if (!empty($this->error) && !$result->users()) {
            return [$this->error];
        }

        return [];
    }

    /**
     * The answer as a string, or null for a shape a name box cannot post, such
     * as an array from a hand-built request. A missing answer is empty.
     */
    protected function cav7fuqAnswerText($answer): ?string
    {
        if ($answer === null) {
            return '';
        }

        return is_scalar($answer) ? (string) $answer : null;
    }

    /**
     * Resolves an answer once per request. The key carries the type as well as
     * the answer, so a question whose type changes mid-request is not answered
     * from the other type's result.
     */
    protected function cav7fuqResolve(string $raw): Result
    {
        $takesSeveral = QuestionType::takesSeveral((string) $this->type);
        $key = ($takesSeveral ? 'several:' : 'one:') . $raw;

        if (!isset($this->cav7fuqResolved[$key])) {
            $this->cav7fuqResolved[$key] = AnswerResolution::resolve(
                $raw,
                $takesSeveral,
                function (string $name): ?array {
                    $user = $this->finder(UserFinder::class)
                        ->where('username', $name)
                        ->fetchOne();

                    return $user ? ['id' => $user->user_id, 'username' => $user->username] : null;
                }
            );
        }

        return $this->cav7fuqResolved[$key];
    }
}
