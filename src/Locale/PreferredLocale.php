<?php

namespace App\Locale;

use App\Locale\Model\SupportedLocale;

/**
 * The language the interface speaks, and the one the agent answers in: the only way to read or change it. Of the member
 * logged in, of a visitor, or of the application (App\Locale\UserPreferredLocale).
 */
interface PreferredLocale
{
    /**
     * The default one until chosen.
     */
    public function get(): SupportedLocale;

    /**
     * The caller flushes.
     */
    public function set(SupportedLocale $locale): void;

    /**
     * False until the user picks one: the interface then offers the language of the browser.
     */
    public function isChosen(): bool;
}
