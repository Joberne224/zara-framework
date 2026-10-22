<?php
declare(strict_types=1);

namespace Zara\Auth\Storage;

use Zara\Auth\Interfaces\IdentityInterface;
use Zara\Auth\Interfaces\IdentityStorageInterface;
use Zara\Core\Http\Interfaces\SessionInterface;

final class SessionIdentityStorage implements IdentityStorageInterface
{
    private const SESSION_KEY = '_zara_auth_identity';

    public function __construct(
        private readonly SessionInterface $session,
    ) {
    }

    public function set(IdentityInterface $identity): void
    {
        $this->session->regenerate(); 
        $this->session->set(self::SESSION_KEY, $identity);
    }

    public function get(): ?IdentityInterface
    {
        $stored = $this->session->get(self::SESSION_KEY);

        return $stored instanceof IdentityInterface ? $stored : null;
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerate();
    }
}