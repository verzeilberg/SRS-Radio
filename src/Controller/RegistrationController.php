<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    /** @var string[] */
    private array $allowedDomains;

    public function __construct(string $allowedRegistrationDomains)
    {
        $domains = array_map('trim', explode(',', $allowedRegistrationDomains));
        $this->allowedDomains = array_filter($domains);
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_user_dashboard');
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $name = $request->request->get('name');
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirm_password');

            $errors = [];

            if (!$email) {
                $errors[] = 'Email is required.';
            }

            if (!$password) {
                $errors[] = 'Password is required.';
            }

            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }

            if (empty($errors)) {
                $domain = mb_substr($email, mb_strpos($email, '@') + 1);
                if (!empty($this->allowedDomains) && !in_array($domain, $this->allowedDomains, true)) {
                    $errors[] = 'Registration is limited to specific email domains.';
                }
            }

            if (empty($errors)) {
                $existing = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existing) {
                    $errors[] = 'An account with this email already exists.';
                }
            }

            if (empty($errors)) {
                $user = new User();
                $user->setEmail($email);
                $user->setName($name ?: null);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setRoles(['ROLE_USER']);

                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Registration successful. You can now sign in.');

                return $this->redirectToRoute('app_login');
            }

            return $this->render('security/register.html.twig', [
                'errors' => $errors,
                'email' => $email,
                'name' => $name,
            ]);
        }

        return $this->render('security/register.html.twig', [
            'errors' => [],
            'email' => '',
            'name' => '',
        ]);
    }
}
