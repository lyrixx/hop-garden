<?php

namespace App\Chat\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChatMessageInput
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 500)]
        public string $text = '',
    ) {
    }
}
