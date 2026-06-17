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
            if ($award->award_id === $pucAwardId)
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
        // the saved record_id to build the citation path. So if attaching the
        // citation fails, roll the award row back to keep a grant all-or-nothing
        // for its date — a citationless grant would otherwise stick and the
        // idempotency guard would skip it forever, leaving it permanently
        // imageless. Rolling back lets a future re-run retry the whole grant.
        try
        {
            /** @var Image $imageService */
            $imageService = \XF::service('NF\Rosters:AwardRecord\Image', $award);
            if (!$imageService->setImage($citationPath))
            {
                throw new \RuntimeException(
                    'citation image rejected: ' . $this->errorText($imageService->getError())
                );
            }
            $imageService->updateImage();
        }
        catch (\Throwable $e)
        {
            $award->delete();
            throw $e;
        }
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

    public function logError(string $message): void
    {
        \XF::logError($message);
    }

    private function errorText($error): string
    {
        if ($error instanceof \XF\Phrase)
        {
            return $error->render();
        }

        return (string) $error;
    }
}
