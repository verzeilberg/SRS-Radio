<?php

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;

class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        $resetPasswordRequest = new ResetPasswordRequest();
        $resetPasswordRequest->setUser($user);
        $resetPasswordRequest->setExpiresAt($expiresAt);
        $resetPasswordRequest->setSelector($selector);
        $resetPasswordRequest->setHashedToken($hashedToken);
        $resetPasswordRequest->setRequestedAt(new \DateTimeImmutable());

        $this->getEntityManager()->persist($resetPasswordRequest);
        $this->getEntityManager()->flush();

        return $resetPasswordRequest;
    }
}