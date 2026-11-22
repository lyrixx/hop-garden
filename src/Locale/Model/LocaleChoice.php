<?php

namespace App\Locale\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The language picked in the banner or in the footer, and the page to go back to.
 */
final readonly class LocaleChoice
{
    public function __construct(
        public SupportedLocale $locale,
        /**
         * A path of this application only: never "//host" nor "/\host", which a browser takes for another site.
         */
        #[Assert\Regex('~^/(?![/\\\])~')]
        public string $returnTo = '/',
    ) {
    }
}
