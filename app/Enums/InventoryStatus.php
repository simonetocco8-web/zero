<?php

namespace App\Enums;

enum InventoryStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case ChangePending = 'change_pending';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public const REVIEW_FILTER = 'awaiting_review';

    public static function reviewValues(): array
    {
        return [self::Pending->value, self::ChangePending->value];
    }

    public function needsReview(): bool
    {
        return in_array($this->value, self::reviewValues(), true);
    }
}
