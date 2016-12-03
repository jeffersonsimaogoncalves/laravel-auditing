<?php

namespace OwenIt\Auditing;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit as AuditModel;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use UnexpectedValueException;

trait Auditable
{
    /**
     * Attributes to include in the Audit.
     *
     * @var array
     */
    protected $include = [];

    /**
     * Attributes to exclude from the Audit.
     *
     * @var array
     */
    protected $exclude = [];

    /**
     * Audit in strict mode?
     *
     * @var bool
     */
    protected $strictMode = false;

    /**
     * Audit driver.
     *
     * @var string
     */
    protected $auditDriver;

    /**
     * Audit threshold.
     *
     * @var int
     */
    protected $auditThreshold = 0;

    /**
     * Audit event name.
     *
     * @var string
     */
    protected $auditEvent;

    /**
     * Auditable boot.
     *
     * @return void
     */
    public static function bootAuditable()
    {
        if (static::isAuditingEnabled()) {
            static::observe(new AuditableObserver());
        }
    }

    /**
     * Auditable Model audits.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function audits()
    {
        return $this->morphMany(AuditModel::class, 'auditable');
    }

    /**
     * Update excluded attributes.
     *
     * @return void
     */
    protected function updateExclusions()
    {
        foreach ($this->attributes as $attribute => $value) {
            // When in strict mode, hidden and non visible attributes will be excluded
            if ($this->strictMode && (in_array($attribute, $this->hidden) || !in_array($attribute, $this->visible))) {
                $this->exclude[] = $attribute;
                continue;
            }

            // Apart from null, non scalar values will be excluded
            if (is_object($value) && !method_exists($value, '__toString') || is_array($value)) {
                $this->exclude[] = $attribute;
            }
        }

        // Remove any duplicates that may exist
        $this->exclude = array_unique($this->exclude);
    }

    /**
     * Set the old/new attributes corresponding to a created event.
     *
     * @param array $old
     * @param array $new
     *
     * @return void
     */
    protected function auditCreatedAttributes(array &$old, array &$new)
    {
        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $new[$attribute] = $value;
            }
        }
    }

    /**
     * Set the old/new attributes corresponding to an updated event.
     *
     * @param array $old
     * @param array $new
     *
     * @return void
     */
    protected function auditUpdatedAttributes(array &$old, array &$new)
    {
        foreach ($this->getModifiedAttributes() as $attribute => $value) {
            $old[$attribute] = array_get($this->original, $attribute);
            $new[$attribute] = array_get($this->attributes, $attribute);
        }
    }

    /**
     * Set the old/new attributes corresponding to a deleted event.
     *
     * @param array $old
     * @param array $new
     *
     * @return void
     */
    protected function auditDeletedAttributes(array &$old, array &$new)
    {
        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $old[$attribute] = $value;
            }
        }
    }

    /**
     * Set the old/new attributes corresponding to a restored event.
     *
     * @param array $old
     * @param array $new
     *
     * @return void
     */
    protected function auditRestoredAttributes(array &$old, array &$new)
    {
        // We apply the same logic as the deleted,
        // but the old/new order is swapped
        $this->auditDeletedAttributes($new, $old);
    }

    /**
     * {@inheritdoc}
     */
    public function toAudit()
    {
        if (!$this->isEventAuditable($this->auditEvent)) {
            return [];
        }

        $method = 'audit'.Str::studly($this->auditEvent).'Attributes';

        if (!method_exists($this, $method)) {
            throw new RuntimeException(sprintf('Unable to handle "%s" event, %s() method missing',
                $this->auditEvent,
                $method
            ));
        }

        $this->updateExclusions();

        $old = [];
        $new = [];

        $this->{$method}($old, $new);

        return $this->transformAudit([
            'id'             => (string) Uuid::uuid4(),
            'old'            => $old,
            'new'            => $new,
            'event'          => $this->auditEvent,
            'auditable_id'   => $this->getKey(),
            'auditable_type' => $this->getMorphClass(),
            'user_id'        => $this->resolveUserId(),
            'url'            => $this->resolveUrl(),
            'ip_address'     => Request::ip(),
            'created_at'     => $this->freshTimestamp(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function transformAudit(array $data)
    {
        return $data;
    }

    /**
     * Resolve the ID of the logged User
     *
     * @throws UnexpectedValueException
     * @return mixed|null
     */
    protected function resolveUserId()
    {
        $resolver = Config::get('audit.user.resolver');

        if (!is_callable($resolver)) {
            throw new UnexpectedValueException('Invalid User resolver type, callable expected');
        }

        return $resolver();
    }

    /**
     * Resolve the current request URL if available.
     *
     * @return string
     */
    protected function resolveUrl()
    {
        if (App::runningInConsole()) {
            return 'console';
        }

        return Request::fullUrl();
    }

    /**
     * Get the modified attributes.
     *
     * @return array
     */
    private function getModifiedAttributes()
    {
        $modified = [];

        foreach ($this->getDirty() as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $modified[$attribute] = $value;
            }
        }

        return $modified;
    }

    /**
     * Determine if an attribute is eligible for auditing.
     *
     * @param string $attribute
     *
     * @return bool
     */
    private function isAttributeAuditable($attribute)
    {
        // The attribute should not be audited
        if (in_array($attribute, $this->exclude)) {
            return false;
        }

        // The attribute is auditable when explicitly
        // listed or when the include array is empty
        return in_array($attribute, $this->include) || empty($this->include);
    }

    /**
     * Determine whether an event is auditable.
     *
     * @param string $event
     *
     * @return bool
     */
    private function isEventAuditable($event)
    {
        return in_array($event, $this->getAuditableEvents());
    }

    /**
     * {@inheritdoc}
     */
    public function setAuditEvent($event)
    {
        $this->auditEvent = $this->isEventAuditable($event) ? $event : null;

        return $this;
    }

    /**
     * Get the auditable events.
     *
     * @return array
     */
    public function getAuditableEvents()
    {
        if (isset($this->auditableEvents)) {
            return $this->auditableEvents;
        }

        return [
            'created',
            'updated',
            'deleted',
            'restored',
        ];
    }

    /**
     * Determine whether auditing is enabled.
     *
     * @return bool
     */
    public static function isAuditingEnabled()
    {
        if (App::runningInConsole()) {
            return (bool) Config::get('audit.console', false);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditDriver()
    {
        return $this->auditDriver;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditThreshold()
    {
        return $this->auditThreshold;
    }
}
