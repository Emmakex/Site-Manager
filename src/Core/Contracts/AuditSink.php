<?php
declare(strict_types=1);

namespace SiteManager\Core\Contracts;

use SiteManager\Core\Model\AuditEvent;

interface AuditSink
{
    public function append(AuditEvent $event): void;
}
