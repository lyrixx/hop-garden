<?php

namespace App\Mcp\Command;

use App\Account\UserRepository;
use App\Garden\CurrentGarden;
use App\Garden\GardenRepository;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The MCP server of the external clients over STDIO, for a developer on their machine (castor mcp:stdio --user=...):
 * no OAuth over STDIO, as the MCP specification says, the user is chosen on the command line. Logged in as that member
 * for the whole session, bound to one of their gardens as the agent and, in phase 3, an OAuth client are
 * (CurrentGarden::TOKEN_ATTRIBUTE): the tools, the voters and the repositories see what they would see on the website.
 * Nothing but JSON-RPC on STDOUT: the errors go to STDERR.
 */
#[AsCommand(name: 'app:mcp:stdio', description: 'Serves the MCP server of the external clients over STDIO, as a member, in one of their gardens')]
final readonly class StdioServerCommand
{
    public function __construct(
        #[Target('default server')]
        private Server $server,
        private UserRepository $userRepository,
        private GardenRepository $gardenRepository,
        private TokenStorageInterface $tokenStorage,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The email of the member the tools act for')]
        string $user = '',
        #[Option(description: 'The id of their garden, the one they joined first by default')]
        ?int $garden = null,
    ): int {
        $errors = $io->getErrorStyle();

        $member = '' !== $user ? $this->userRepository->findOneByEmail($user) : null;
        if (null === $member || !\in_array('ROLE_USER', $member->getRoles(), true)) {
            $errors->error(\sprintf('No approved member with the email "%s": pass --user=<email>.', $user));

            return Command::INVALID;
        }

        $gardens = $this->gardenRepository->findOfMember($member);
        $bound = null !== $garden ? $this->gardenRepository->findOneOfMember($garden, $member) : $gardens[0] ?? null;
        if (null === $bound) {
            $errors->error(null !== $garden ? \sprintf('%s is no member of garden %d.', $member->getEmail(), $garden) : \sprintf('%s has no garden yet: they create one on the website first.', $member->getEmail()));

            return Command::INVALID;
        }

        $token = new PostAuthenticationToken($member, 'mcp_stdio', $member->getRoles());
        $token->setAttribute(CurrentGarden::TOKEN_ATTRIBUTE, $bound->getId());
        $this->tokenStorage->setToken($token);

        $errors->writeln(\sprintf('<comment>MCP over STDIO as %s, in the garden "%s" (%d).</comment>', $member->getEmail(), $bound->getName(), $bound->getId()));

        $this->server->run(new StdioTransport(logger: $this->logger));

        return Command::SUCCESS;
    }
}
