<?php
declare(strict_types=1);

namespace Zara\Authorization;

use InvalidArgumentException;
use Zara\Auth\Interfaces\IdentityInterface;
use Zara\Authorization\Interfaces\AuthorizationInterface;
use Zara\Authorization\Interfaces\AuthorizationVoterInterface;
use Zara\Permission\PermissionRegistry;

final class Authorization implements AuthorizationInterface
{
    /** @var list<AuthorizationVoterInterface> */
    private array $voters;

    /**
     * @param iterable<AuthorizationVoterInterface> $voters
     */
    public function __construct(
        private readonly PermissionRegistry $permissions,
        iterable $voters = [],
    ) {
        $this->voters = [];
        foreach ($voters as $voter) {
            if (!$voter instanceof AuthorizationVoterInterface) {
                throw new InvalidArgumentException('Authorization voters must implement AuthorizationVoterInterface.');
            }

            $this->voters[] = $voter;
        }
    }

    public function isGranted(
        IdentityInterface $identity,
        string $permission,
        ?object $subject = null,
    ): bool {
        if (trim($permission) === '') {
            throw new InvalidArgumentException('Permission name must not be empty.');
        }

        $voterGranted = false;
        foreach ($this->voters as $voter) {
            $decision = $voter->vote($identity, $permission, $subject);
            if ($decision === false) {
                return false;
            }

            $voterGranted = $voterGranted || $decision === true;
        }

        return $voterGranted || $this->permissions->grantsAny($identity->getRoles(), $permission);
    }

    public function hasAnyRole(IdentityInterface $identity, array $roles): bool
    {
        return $roles !== [] && array_intersect($roles, $identity->getRoles()) !== [];
    }
}
