<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * What kind of fact a piece of evidence is.
 *
 * The type is what lets evidence be presented, correlated and filtered without a reader having
 * to interpret free text, and it is what tells a report which evidence is safe to show a
 * client and which is not.
 *
 * Some types name the state of a part of the installation — the database, the queue, a plugin —
 * and some name a particular kind of fact within it — a database error, one queue job, a
 * plugin's version. Evidence takes the most particular type that is true of it.
 */
enum EvidenceType: string
{
    case SYSTEM = 'system';
    case DATABASE = 'database';
    case QUEUE = 'queue';
    case PLUGIN = 'plugin';
    case DEPLOYMENT = 'deployment';
    case LOG_ENTRY = 'logEntry';
    case EXCEPTION = 'exception';
    case STACK_TRACE = 'stackTrace';
    case DATABASE_ERROR = 'databaseError';
    case HTTP_RESPONSE = 'httpResponse';
    case QUEUE_JOB = 'queueJob';
    case PLUGIN_VERSION = 'pluginVersion';
    case CRAFT_VERSION = 'craftVersion';
    case PHP_VERSION = 'phpVersion';
    case CONFIGURATION = 'configuration';
    case PROJECT_CONFIG = 'projectConfig';
    case FILESYSTEM = 'filesystem';
    case ENVIRONMENT_VARIABLE = 'environmentVariable';
    case TIMESTAMP = 'timestamp';
    case REQUEST = 'request';
    case ELEMENT = 'element';
    case USER = 'user';
    case MEASUREMENT = 'measurement';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEM => Craft::t('web-doctor', 'System'),
            self::DATABASE => Craft::t('web-doctor', 'Database'),
            self::QUEUE => Craft::t('web-doctor', 'Queue'),
            self::PLUGIN => Craft::t('web-doctor', 'Plugin'),
            self::DEPLOYMENT => Craft::t('web-doctor', 'Deployment'),
            self::LOG_ENTRY => Craft::t('web-doctor', 'Log entry'),
            self::EXCEPTION => Craft::t('web-doctor', 'Exception'),
            self::STACK_TRACE => Craft::t('web-doctor', 'Stack trace'),
            self::DATABASE_ERROR => Craft::t('web-doctor', 'Database error'),
            self::HTTP_RESPONSE => Craft::t('web-doctor', 'HTTP response'),
            self::QUEUE_JOB => Craft::t('web-doctor', 'Queue job'),
            self::PLUGIN_VERSION => Craft::t('web-doctor', 'Plugin version'),
            self::CRAFT_VERSION => Craft::t('web-doctor', 'Craft version'),
            self::PHP_VERSION => Craft::t('web-doctor', 'PHP version'),
            self::CONFIGURATION => Craft::t('web-doctor', 'Configuration'),
            self::PROJECT_CONFIG => Craft::t('web-doctor', 'Project Config'),
            self::FILESYSTEM => Craft::t('web-doctor', 'Filesystem'),
            self::ENVIRONMENT_VARIABLE => Craft::t('web-doctor', 'Environment variable'),
            self::TIMESTAMP => Craft::t('web-doctor', 'Timestamp'),
            self::REQUEST => Craft::t('web-doctor', 'Request'),
            self::ELEMENT => Craft::t('web-doctor', 'Element'),
            self::USER => Craft::t('web-doctor', 'User'),
            self::MEASUREMENT => Craft::t('web-doctor', 'Measurement'),
        };
    }

    /**
     * Whether evidence of this type may appear in a report written for a client.
     *
     * Stated as a list of what is allowed, so anything not on it — including an evidence type
     * added later — is withheld until someone decides otherwise. A default of "safe" would mean
     * every future type leaked into client reports until the day somebody noticed, and the
     * whole point of this method is to be the thing that notices.
     *
     * Being client-safe is a floor, not the whole of report safety: redaction still applies to
     * everything that goes out.
     */
    public function isClientSafe(): bool
    {
        return match ($this) {
            self::CRAFT_VERSION,
            self::PHP_VERSION,
            self::PLUGIN_VERSION,
            self::ENVIRONMENT_VARIABLE,
            self::TIMESTAMP,
            self::MEASUREMENT => true,
            default => false,
        };
    }
}
