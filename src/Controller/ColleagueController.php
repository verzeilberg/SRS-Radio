<?php
namespace App\Controller;

use App\Entity\Colleague;
use App\Repository\ColleagueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/colleagues')]
class ColleagueController extends AbstractController
{
    public function __construct(
        private ColleagueRepository $repository,
        private EntityManagerInterface $em,
        private string $projectDir,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('colleague/index.html.twig', [
            'colleagues' => $this->repository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $name      = trim((string) $request->request->get('name', ''));
        $birthdate = trim((string) $request->request->get('birthdate', ''));

        if ($name === '' || $birthdate === '') {
            $this->addFlash('error', 'Name and birthdate are required.');
            return $this->redirectToRoute('app_colleague_index');
        }

        try {
            $date = new \DateTimeImmutable($birthdate);
        } catch (\Exception) {
            $this->addFlash('error', 'Invalid birthdate format.');
            return $this->redirectToRoute('app_colleague_index');
        }

        $pictureFilename = null;
        $file = $request->files->get('picture');
        if ($file) {
            $slugger  = new AsciiSlugger();
            $safe     = $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
            $filename = $safe . '-' . uniqid() . '.' . $file->guessExtension();
            try {
                $file->move($this->projectDir . '/public/images/colleagues', $filename);
                $pictureFilename = $filename;
            } catch (FileException) {
                $this->addFlash('error', 'Failed to upload picture.');
            }
        }

        $this->em->persist(new Colleague($name, $date, $pictureFilename));
        $this->em->flush();

        $this->addFlash('success', "{$name} added.");
        return $this->redirectToRoute('app_colleague_index');
    }

    #[Route('/{id}', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        if ($request->request->get('_method') !== 'DELETE') {
            return $this->redirectToRoute('app_colleague_index');
        }

        $colleague = $this->repository->find($id);
        if ($colleague) {
            if ($colleague->getPicture()) {
                @unlink($this->projectDir . '/public/images/colleagues/' . $colleague->getPicture());
            }
            $this->em->remove($colleague);
            $this->em->flush();
            $this->addFlash('success', $colleague->getName() . ' removed.');
        }

        return $this->redirectToRoute('app_colleague_index');
    }
}
