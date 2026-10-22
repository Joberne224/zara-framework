<?php
// Zara/Auth/Authentication.php
declare(strict_types=1);

namespace Zara\Auth;

use Zara\Auth\Interfaces\AuthenticationInterface;
use Zara\Auth\Interfaces\IdentityInterface;
use Zara\Auth\Interfaces\IdentityProviderInterface;
use Zara\Auth\Interfaces\RefreshableIdentityProviderInterface;
use Zara\Auth\Interfaces\IdentityStorageInterface;

final class Authentication implements AuthenticationInterface
{
    public function __construct(
        private readonly IdentityProviderInterface $provider,
        private readonly IdentityStorageInterface $storage,
    ) {
    }

    public function authenticate(string $identifier, string $password): bool
    {
        $identity = $this->provider->retrieveByCredentials($identifier, $password);

        if ($identity === null) {
            return false;
        }

        $this->storage->set($identity);

        return true;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?IdentityInterface
    {
        $identity = $this->storage->get();
        if ($identity === null || !$this->provider instanceof RefreshableIdentityProviderInterface) {
            return $identity;
        }

        $freshIdentity = $this->provider->refreshIdentity($identity);
        if ($freshIdentity === null) {
            $this->storage->clear();
        }

        return $freshIdentity;
    }

    public function logout(): void
    {
        $this->storage->clear();
    }
}
