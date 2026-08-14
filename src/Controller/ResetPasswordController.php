<?php

namespace App\Controller;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password_request')]
    public function request(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_user_dashboard');
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');

            if (!$email) {
                $this->addFlash('error', 'Please enter your email address.');
                return $this->render('security/request_password.html.twig');
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);

            if (!$user) {
                $this->addFlash('success', 'If the email exists, a password reset link has been sent.');
                return $this->render('security/request_password.html.twig');
            }

            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);
                $selector = substr($resetToken->getToken(), 0, 20);
                $resetPasswordRequest = $this->resetPasswordRequestRepository->findOneBy(['selector' => $selector]);

                if (!$resetPasswordRequest) {
                    throw new \RuntimeException('Failed to create password reset request');
                }

                $this->sendResetEmail($user, $resetPasswordRequest);

                $this->addFlash('success', 'If the email exists, a password reset link has been sent.');

                return $this->redirectToRoute('app_check_email');
            } catch (TooManyPasswordRequestsException $e) {
                $this->addFlash('error', 'Too many password reset requests. Please wait a few minutes before trying again.');
            } catch (ResetPasswordExceptionInterface $e) {
                $this->addFlash('error', 'An error occurred. Please try again later.');
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Throwable: ' . ($e->getMessage() ?: 'no message') . ' | Class: ' . get_class($e) . ' | File: ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        return $this->render('security/request_password.html.twig');
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_user_dashboard');
        }

        return $this->render('security/check_email.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function reset(Request $request, string $token): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_user_dashboard');
        }

        $resetPasswordRequest = $this->resetPasswordRequestRepository->findOneBy(['selector' => $token]);

        if (!$resetPasswordRequest) {
            $this->addFlash('error', 'Invalid or expired reset link.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        $user = $resetPasswordRequest->getUser();

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirm_password');

            if (!$password || !$confirmPassword) {
                $this->addFlash('error', 'Please fill in all fields.');
            } elseif ($password !== $confirmPassword) {
                $this->addFlash('error', 'Passwords do not match.');
            } else {
                try {
                    $this->resetPasswordHelper->removeResetRequest($token);
                    $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    $this->addFlash('success', 'Your password has been reset successfully.');
                    return $this->redirectToRoute('app_login');
                } catch (ResetPasswordExceptionInterface $e) {
                    $this->addFlash('error', 'An error occurred. Please try again later.');
                }
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
        ]);
    }

    private function sendResetEmail(User $user, ResetPasswordRequest $resetPasswordRequest): void
    {
        $resetUrl = $this->generateUrl('app_reset_password', ['token' => $resetPasswordRequest->getSelector()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->from('noreply@srsradio.local')
            ->to($user->getEmail())
            ->subject('SRS Radio - Password Reset')
            ->html($this->renderView('security/email/reset_password.html.twig', [
                'resetUrl' => $resetUrl,
                'user' => $user,
            ]));

        $this->mailer->send($email);
    }
}