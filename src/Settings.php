<?php

namespace Ernestdefoe\Resurface;

final class Settings
{
    /** Hours a discussion must sit without activity before its owner may bump it. */
    public const COOLDOWN = 'ernestdefoe-resurface.cooldown_hours';

    /** Bumps a member may make in any 24 hours; 0 is no limit. */
    public const DAILY = 'ernestdefoe-resurface.daily_limit';

    /** Per-group overrides: {"<group id>": {"cooldown": hours|null, "daily": n|null}}. */
    public const GROUPS = 'ernestdefoe-resurface.group_limits';

    /** Tag ids members may bump in, as a JSON list; empty is every tag. */
    public const TAGS = 'ernestdefoe-resurface.tags';

    /** Days the bump log keeps; 0 keeps it for good. */
    public const LOG_DAYS = 'ernestdefoe-resurface.log_days';

    public const DEFAULTS = [
        self::COOLDOWN => 24,
        self::DAILY => 1,
        self::GROUPS => '{}',
        self::TAGS => '[]',
        self::LOG_DAYS => 90,
    ];
}
