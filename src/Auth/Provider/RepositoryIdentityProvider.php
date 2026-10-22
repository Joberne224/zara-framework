<?php
declare(strict_types=1);

namespace Zara\Auth\Provider;

use Zara\Auth\GenericIdentity;
use Zara\Auth\Hash\PasswordHasherInterface;
use Zara\Auth\Hash\RehashingPasswordHasherInterface;
use Zara\Auth\Interfaces\CredentialsRepositoryInterface;
use Zara\Auth\Interfaces\IdentityLookupRepositoryInterface;
use Zara\Auth\Interfaces\IdentityInterface;
use Zara\Auth\Interfaces\PasswordHashUpdaterInterface;
use Zara\Auth\Interfaces\RefreshableIdentityProviderInterface;

final class RepositoryIdentityProvider implements RefreshableIdentityProviderInterface
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$/YM0yaY9F7JrNjRPntm0he6ym60EDbGoghhEVEcoByCE./dMGYYLy';

    public function __construct(
        private readonly CredentialsRepositoryInterface $repository,
        private readonly PasswordHasherInterface $hasher,
    ) {
    }

    public function retrieveByCredentials(string $identifier, string $password): ?IdentityInterface
    {
        $entity = $this->repository->findByIdentifier($identifier);

        if ($entity === null) {
            // Keep the unknown-identifier path close to the normal password
            // verification path to reduce account-enumeration timing leaks.
            $this->hasher->verify($password, self::DUMMY_PASSWORD_HASH);

            return null;
        }

        $passwordHash = $this->passwordHashOf($entity);

        if (!$this->hasher->verify($password, $passwordHash)) {
            return null;
        }

        $identity = $this->identityOf($entity);

        if ($this->hasher instanceof RehashingPasswordHasherInterface
            && $this->hasher->needsRehash($passwordHash)
            && $this->repository instanceof PasswordHashUpdaterInterface) {
            $this->repository->updatePasswordHash($identity->getId(), $this->hasher->hash($password));
        }

        return $identity;
    }

    public function refreshIdentity(IdentityInterface $identity): ?IdentityInterface
    {
        if (!$this->repository instanceof IdentityLookupRepositoryInterface) {
            return $identity;
        }

        $entity = $this->repository->findById($identity->getId());

        return $entity === null ? null : $this->identityOf($entity);
    }

    private function passwordHashOf(object $entity): string
    {
        if (!method_exists($entity, 'getPasswordHash')) {
            throw new \LogicException(sprintf(
                'Class "%s" must provide getPasswordHash(): string.',
                $entity::class
            ));
        }

        $hash = $entity->getPasswordHash();
        if (!is_string($hash) || $hash === '') {
            throw new \LogicException(sprintf('Class "%s" returned an invalid password hash.', $entity::class));
        }

        return $hash;
    }

    private function identityOf(object $entity): IdentityInterface
    {
        if (method_exists($entity, 'toIdentity')) {
            $identity = $entity->toIdentity();
            if (!$identity instanceof IdentityInterface) {
                throw new \LogicException(sprintf('Class "%s" must return an IdentityInterface from toIdentity().', $entity::class));
            }

            return $identity;
        }

        if (!method_exists($entity, 'getId')) {
            throw new \LogicException(sprintf(
                'Class "%s" must provide toIdentity() or getId().',
                $entity::class
            ));
        }

        $id = $entity->getId();
        if (!is_int($id) && !is_string($id)) {
            throw new \LogicException(sprintf('Class "%s" returned an invalid identity ID.', $entity::class));
        }

        $roles = method_exists($entity, 'getRoles') ? $entity->getRoles() : [];
        if (!is_array($roles) || array_filter($roles, static fn (mixed $role): bool => !is_string($role)) !== []) {
            throw new \LogicException(sprintf('Class "%s" returned invalid identity roles.', $entity::class));
        }

        return new GenericIdentity($id, array_values($roles));
    }
}
