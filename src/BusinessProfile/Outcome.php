<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * What the sync decided and did for one item, as the activity log records it.
 */
final readonly class Outcome
{
    /**
     * @param  SyncItem  $item  The item it is about.
     * @param  Mode  $mode  The mode its silo ran in; off when it never reached a silo.
     * @param  Action  $action  What was decided.
     * @param  string  $reason  Why, written for a person.
     * @param  list<string>  $fields  The fields written, or that a dry run would write.
     * @param  list<string>  $attributes  The attributes written, or that a dry run would write.
     * @param  string  $listingName  The matched or created listing's resource name, when there is one.
     * @param  bool  $retryable  Whether a failure was a rate limit that a later attempt can clear.
     * @param  bool  $written  Whether anything was sent to Google.
     * @param  list<string>  $photos  The addresses of the photos this run set out to send, or that a dry run would send; `$photosSent` names those that went.
     * @param  list<string>  $photosSent  The addresses Google accepted in this run, for the caller to record against the listing.
     * @param  ?int  $resumeAt  For a deferred create, the Unix time it may go again.
     */
    public function __construct(
        public SyncItem $item,
        public Mode $mode,
        public Action $action,
        public string $reason,
        public array $fields = [],
        public array $attributes = [],
        public string $listingName = '',
        public bool $retryable = false,
        public bool $written = false,
        public array $photos = [],
        public array $photosSent = [],
        public ?int $resumeAt = null,
    ) {}

    /**
     * Whether the outcome is final for the content it was decided from.
     *
     * A match, a skip and a write that went through are final. A review depends on
     * Google's state, an error may clear, and a dry run wrote nothing, so the next
     * save has to run again. A deferred create goes again once its resume time comes.
     */
    public function isSettled(): bool
    {
        return match ($this->action) {
            Action::Noop, Action::Skip => true,
            Action::Update, Action::Create => $this->written,
            Action::Review, Action::Error, Action::Deferred => false,
        };
    }

    /**
     * The outcome as plain data for a log entry.
     *
     * @return array{store_code: string, kind: string, silo: ?string, mode: string, action: string, reason: string, fields: list<string>, attributes: list<string>, unresolved_categories: list<string>, listing_name: string, retryable: bool, photos: list<string>, photos_sent: list<string>, resume_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'store_code' => $this->item->storeCode,
            'kind' => $this->item->kind->value,
            'silo' => $this->item->silo,
            'mode' => $this->mode->value,
            'action' => $this->action->value,
            'reason' => $this->reason,
            'fields' => $this->fields,
            'attributes' => $this->attributes,
            'unresolved_categories' => $this->item->unresolvedCategories,
            'listing_name' => $this->listingName,
            'retryable' => $this->retryable,
            'photos' => $this->photos,
            'photos_sent' => $this->photosSent,
            'resume_at' => $this->resumeAt === null ? null : CreateQuota::format($this->resumeAt),
        ];
    }
}
