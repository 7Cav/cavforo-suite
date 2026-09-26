<?php

namespace Cav7\FormsUserQuestion\Snog\Forms\Entity;

use Cav7\FormsUserQuestion\AnswerResolution;
use Cav7\FormsUserQuestion\AnswerResolution\Result;
use Cav7\FormsUserQuestion\QuestionType;
use XF\Finder\UserFinder;

/**
 * Accepts or refuses a forum user question's answer (issue #312), and writes an
 * accepted one where the submission goes (issue #313). Every other question
 * type passes straight through to the vendor.
 *
 * AnswerResolution holds the rule. This class feeds it the posted answer and a
 * lookup that runs one user finder query per name with `username = ?`, so the
 * column's collation decides the match. It then turns a refusal into the
 * question's error, which blocks the submission. An accepted answer goes into
 * the thread post, a reply and a conversation as one [USER=id] link per forum
 * user, and into the thread title, the form log and the emails as plain
 * usernames. Both are spelled the way the account spelled the name at
 * submission, so the stored answer is a snapshot and the post's id is the
 * lasting record of who was named. Naming a forum user alerts nobody. XenForo
 * alerts only on typed `@Name` text, its mention parser skips [USER] tags, and
 * nothing here writes an `@`.
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
 *    it. cav7fuqResolve() keeps each answer's result, so those methods reuse
 *    it instead of querying again. For a refused answer they give what the
 *    vendor gives for any string, the answer as posted. The submission fails
 *    on the question's error either way, and a title that still resolves
 *    keeps a second, misleading "title could not be created" error off it.
 *  - getFormattedAnswer($answer, $context) is the only way an answer reaches
 *    the submission's text. Submit::processAnswer() calls it with 'store' for
 *    the answer row in the form log, xf_snog_forms_answers, which the log view
 *    prints escaped. It calls it again with each context in
 *    Form::getReportContentTypes(), both directly and through
 *    getFormattedReportMessage(), which puts the text into the question's
 *    format. Those contexts are 'post', for the new thread's first post and
 *    for a reply to an existing thread, whether the form's oldthread or a
 *    quick reply; 'conversation_message', for the conversation a form can
 *    start; and 'email', for the notification and confirmation emails, which
 *    render the message as email HTML. No template calls it, and the vendor
 *    never uses the default 'message' context. Only 'post' and
 *    'conversation_message' get [USER] tags. Every other context, including
 *    one a release adds, gets plain usernames, which are safe anywhere. If a
 *    release renames 'post' or 'conversation_message', the thread quietly
 *    shows plain names instead of profile links.
 *  - canUsedForReportTitle() is the vendor's allowlist of question types a
 *    thread title may use. The form save in Admin\Controller\Forms refuses a
 *    title whose {An} names a question it rejects, so "Forum user" is allowed
 *    and "Forum users" is refused, as the vendor refuses its other
 *    multi-answer types. The same method fills two dropdowns on the form's
 *    settings: the question that routes the notification email, and the
 *    question whose answer gets the confirmation email. "Forum user" appears
 *    in both. The confirmation email goes only to an answer that is an email
 *    address, so choosing it there sends none; the README warns against it.
 *    The vendor checks title eligibility only when an existing form is saved,
 *    never on the change-type screen.
 *  - getTitleAnswer($answer) supplies {An} for the thread title, which also
 *    heads the conversation and is the email subject. Submit calls it once per
 *    answered question, and Repository\Form::getReportTitle() substitutes only
 *    a non-empty string. Anything else leaves {An} unfilled and fails the
 *    submission with "the report title could not be created". The vendor's
 *    own version returns '' for a type its allowlist rejects. This one
 *    returns the usernames for both types, so a title that still names a
 *    question converted to "Forum users" keeps working until the form's next
 *    save refuses it.
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
     * A [USER] link per forum user for a post or a conversation, and plain
     * usernames for every other context. An answer that was not posted, or was
     * refused, gets the vendor's own text.
     */
    public function getFormattedAnswer($answer, $context = 'message')
    {
        $result = $this->cav7fuqAcceptedResult($answer);
        if ($result === null) {
            return parent::getFormattedAnswer($answer, $context);
        }

        return in_array($context, ['post', 'conversation_message'], true)
            ? $result->postText()
            : $result->plainText();
    }

    /**
     * "Forum user" may go in a thread title. "Forum users" may not, as the
     * vendor keeps its other multi-answer types out.
     */
    public function canUsedForReportTitle()
    {
        if (!$this->isCav7FuqForumUserType()) {
            return parent::canUsedForReportTitle();
        }

        return !QuestionType::takesSeveral((string) $this->type);
    }

    /**
     * The usernames, for both types. A refused answer gives the answer as
     * posted, so its question's error is the only one the filer sees.
     */
    public function getTitleAnswer($answer)
    {
        if (!$this->isCav7FuqForumUserType()) {
            return parent::getTitleAnswer($answer);
        }

        $result = $this->cav7fuqAcceptedResult($answer);
        if ($result === null) {
            return is_scalar($answer) ? (string) $answer : '';
        }

        return $result->plainText();
    }

    /**
     * The result for an accepted answer to a forum user question. Null when
     * this is another type, nothing was posted, or the answer was refused.
     */
    protected function cav7fuqAcceptedResult($answer): ?Result
    {
        if (!$this->isCav7FuqForumUserType() || $answer === null) {
            return null;
        }

        $raw = $this->cav7fuqAnswerText($answer);
        if ($raw === null) {
            return null;
        }

        $result = $this->cav7fuqResolve($raw);

        return $result->isAccepted() ? $result : null;
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
