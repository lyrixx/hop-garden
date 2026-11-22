<?php

namespace App\Locale;

use App\Account\Entity\User;
use App\Locale\Model\SupportedLocale;

/**
 * The language the interface speaks, and the one the agent answers in: the only way to read or change it. Of the member
 * logged in, of a visitor, or of the application (App\Locale\UserPreferredLocale); of a member who is not the one of
 * the request, such as the recipient of a reminder (of()).
 */
interface PreferredLocale
{
    /**
     * The default one until chosen.
     */
    public function get(): SupportedLocale;

    /**
     * Of this member, the one of the application until they choose.
     */
    public function of(User $user): SupportedLocale;

    /**
     * The caller flushes.
     */
    public function set(SupportedLocale $locale): void;

    /**
     * False until the user picks one: the interface then offers the language of the browser.
     */
    public function isChosen(): bool;
}
