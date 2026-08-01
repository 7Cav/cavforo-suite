<?php

namespace Cav7\EnlistmentDefaults;

use NF\Rosters\Entity\RosterUser;
use NF\Rosters\Entity\RosterUserAward;
use NF\Rosters\Entity\ServiceRecord;
use NF\Rosters\Service\AwardRecord\Image;

/**
 * The production EnlistmentGateway: drives the NF/Rosters vendor factories and
 * the citation image service against a real milpac. This is the only class in
 * the addon coupled to the vendor entity world; everything else is plain PHP.
 *
 * It ships none of the vendor's code — it calls the vendor's own
 * RosterUser::getNewAward(), RosterUser::getNewServiceRecord() and the
 * AwardRecord\Image service so a vendor update changes behaviour in one place.
 */
class RosterUserGateway implements EnlistmentGateway
{
    public function __construct(
        private RosterUser $rosterUser
    ) {}

    public function existingPucAwardDates(): array
    {
        $pucAwardId = $this->pucAwardId();
        $dates = [];

        foreach ($this->rosterUser->Awards as $award)
        {
            // Compare as ints, not with ===. The vendor declares award_id as an
            // INT column so the getter normally returns an int, but a strict
            // compare against the int-cast option silently misses if the value
            // ever arrives as a string (a freshly-set value, a different DB
            // driver). A miss here would skip an already-granted PUC and let the
            // idempotency guard re-grant a duplicate — exactly the invariant AC6
            // protects — so the guard is kept type-safe.
            if ((int) $award->award_id === $pucAwardId)
            {
                $dates[] = (int) $award->award_date;
            }
        }

        return $dates;
    }

    public function pucAwardId(): int
    {
        return (int) \XF::options()->cav7EnlistDefPucAwardId;
    }

    public function enlistmentRecordTypeId(): int
    {
        return (int) \XF::options()->cav7EnlistDefRecordTypeId;
    }

    public function visitorUserId(): int
    {
        return (int) \XF::visitor()->user_id;
    }

    public function systemFallbackUserId(): int
    {
        return (int) \XF::options()->cav7EnlistDefSystemUserId;
    }

    public function creationDate(): int
    {
        return (int) $this->rosterUser->added_date;
    }

    public function joinDate(): string
    {
        // The milpac's Join Date custom field, stored as a 'Y-m-d' string. A
        // milpac created with no Join Date (import, API) leaves it blank, and
        // EnlistmentDecisions::enlistmentRecordDate falls the record back to the
        // creation date.
        return (string) ($this->rosterUser->custom_fields->joinDate ?? '');
    }

    public function grantAward(int $awardId, int $awardDate, int $fromUserId, string $citationPath): void
    {
        /** @var RosterUserAward $award */
        $award = $this->rosterUser->getNewAward();
        $award->award_id = $awardId;
        $award->award_date = $awardDate;
        $award->from_user_id = $fromUserId;
        // details has no column default and is not nullable; the vendor's own
        // add path always sets it. A PUC grant carries no per-member note, so
        // it is empty.
        $award->details = '';
        $award->save();

        // The award row must exist before its citation: the image service needs
        // the saved record_id to build the citation path. Attaching the citation
        // and rolling the grant back on failure (so a date stays all-or-nothing
        // and a re-run retries it cleanly) is the intricate part, so it lives in
        // CitationAttacher; here we only adapt the vendor types to its seams.
        //
        // The attacher's cleanup breadcrumbs are logged from the applier path
        // too, so they go through logFailure and get the same milpac stamp. The
        // date is folded in here because the attacher is handed an opaque
        // citation path and has no business parsing a date out of it, and with
        // up to six grants in flight a breadcrumb without one is ambiguous.
        $attacher = new CitationAttacher(
            fn (\Throwable $e, string $context) => $this->logFailure(
                $e,
                $context . ' for ' . gmdate('Y-m-d', $awardDate)
            )
        );

        // Resolving the image service is handed over as a closure rather than
        // done here. The row is saved by this point, so a throw out of
        // \XF::service() — a vendor rename, a container fault — would leave a
        // citationless row that no rollback ever reaches. Run inside attach(),
        // it rolls back like any other attach failure. Nothing between the
        // save() above and the call below can throw: both are plain object
        // constructions.
        $attacher->attach(
            $this->citationAward($award),
            function () use ($award): CitationImage {
                /** @var Image $imageService */
                $imageService = \XF::service('NF\Rosters:AwardRecord\Image', $award);

                return $this->citationImage($imageService);
            },
            $citationPath
        );
    }

    /** Adapt the vendor award row to the CitationAward seam. */
    private function citationAward(RosterUserAward $award): CitationAward
    {
        return new class ($award) implements CitationAward {
            public function __construct(private RosterUserAward $award) {}

            public function delete(): void
            {
                $this->award->delete();
            }
        };
    }

    /** Adapt the vendor AwardRecord\Image service to the CitationImage seam. */
    private function citationImage(Image $imageService): CitationImage
    {
        $errorText = fn () => $this->errorText($imageService->getError());

        return new class ($imageService, $errorText) implements CitationImage {
            /** @param callable(): string $errorText */
            public function __construct(
                private Image $imageService,
                private $errorText
            ) {}

            public function setImage(string $path): bool
            {
                return $this->imageService->setImage($path);
            }

            public function errorText(): string
            {
                return ($this->errorText)();
            }

            public function updateImage(): void
            {
                $this->imageService->updateImage();
            }

            public function deleteFile(): void
            {
                // deleteImageForAwardDelete() removes the copied citation JPG
                // only when citation_date is set on the award. updateImage()
                // sets citation_date in memory right after the copy, so a save()
                // failure still leaves it truthy and the file is cleaned; a
                // setImage() rejection never copied anything and this no-ops.
                $this->imageService->deleteImageForAwardDelete();
            }
        };
    }

    public function writeServiceRecord(int $recordTypeId, string $body, int $recordDate): void
    {
        /** @var ServiceRecord $record */
        $record = $this->rosterUser->getNewServiceRecord();
        $record->record_type_id = $recordTypeId;
        $record->details = $body;
        $record->record_date = $recordDate;
        $record->save();
    }

    /**
     * Re-check on a dev stack after an NF/Rosters or XenForo upgrade: the entry
     * a dropped grant leaves still names the milpac and the member, and still
     * keeps the exception's class and stack trace. tests/FailureLoggingTest.php
     * pins this against stand-ins for \XF and the vendor entities, so what the
     * stack run adds is that the real relation_id, user_id and \XF::logException
     * still behave the way those stand-ins model.
     */
    public function logFailure(\Throwable $e, string $context): void
    {
        // The milpac identity is stamped here rather than threaded through the
        // applier: this is the only side that holds the entity. Without it a
        // dropped grant reads as "failed to grant PUC for 2003-03-18" with no
        // way to tell whose milpac is short an award, and a milpac carrying five
        // of six PUCs looks entirely ordinary otherwise.
        //
        // logException, not logError: the exception keeps its class and stack
        // trace, so the entry says what broke and not merely that something did.
        // Never with a rollback — a failure here must not undo the milpac save.
        \XF::logException($e, false, sprintf(
            'Cav7/EnlistmentDefaults: milpac %d (user %d): %s: ',
            (int) $this->rosterUser->relation_id,
            (int) $this->rosterUser->user_id,
            $context
        ));
    }

    /**
     * Render the image service's rejection reason for the log entry.
     *
     * Re-check on a dev stack after an NF/Rosters upgrade: a rejection the
     * service signals by RETURNING FALSE rather than throwing also leaves no
     * award row behind, and its reason reaches the log entry. The reason arrives
     * as an \XF\Phrase, which is rendered here; a vendor that returns a plain
     * string instead still works, one that returns anything else does not.
     */
    private function errorText($error): string
    {
        if ($error instanceof \XF\Phrase)
        {
            return $error->render();
        }

        return (string) $error;
    }
}
