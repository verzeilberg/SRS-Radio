<?php

namespace App\Controller;

use App\Entity\SongRequest;
use App\Repository\SongRequestRepository;
use App\Service\RadioStateService;
use App\Service\SpotifyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    #[Route('/dashboard', name: 'app_user_dashboard')]
    public function dashboard(): Response
    {
        return $this->render('user/dashboard.html.twig');
    }

    // ── Listener notes ──────────────────────────────────────────────────────

    #[Route('/api/listener-notes', name: 'app_listener_notes', methods: ['GET'])]
    public function listenerNotes(RadioStateService $radioState): JsonResponse
    {
        $notes = array_reverse($radioState->getAllListenerNotes());
        return new JsonResponse($notes);
    }

    #[Route('/api/listener-note', name: 'app_listener_note', methods: ['POST'])]
    public function listenerNote(Request $request, RadioStateService $radioState): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $text = trim($data['note'] ?? '');

        if ($text === '') {
            return new JsonResponse(['error' => 'Note is empty'], 400);
        }

        if (mb_strlen($text) > 300) {
            return new JsonResponse(['error' => 'Note too long (max 300 characters)'], 400);
        }

        $radioState->addListenerNote($text, $this->getUser()->getDisplayName());

        return new JsonResponse(['ok' => true]);
    }

    // ── Song requests ────────────────────────────────────────────────────────

    #[Route('/api/song-search', name: 'app_song_search', methods: ['GET'])]
    public function songSearch(Request $request, SpotifyService $spotify): JsonResponse
    {
        $q = trim($request->query->getString('q'));
        if ($q === '') {
            return new JsonResponse([]);
        }

        return new JsonResponse($spotify->searchTracks($q));
    }

    #[Route('/api/song-request', name: 'app_song_request', methods: ['POST'])]
    public function songRequest(
        Request $request,
        EntityManagerInterface $em,
        SongRequestRepository $repo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $spotifyId  = trim($data['spotifyId']  ?? '');
        $spotifyUri = trim($data['spotifyUri'] ?? '');
        $title      = trim($data['title']      ?? '');
        $artist     = trim($data['artist']     ?? '');
        $imageUrl   = $data['imageUrl'] ?? null;

        if (!$spotifyId || !$title || !$artist) {
            return new JsonResponse(['error' => 'Missing fields'], 400);
        }

        $songRequest = new SongRequest($spotifyId, $spotifyUri, $title, $artist, $imageUrl, $this->getUser()->getDisplayName());
        $em->persist($songRequest);
        $em->flush();

        return new JsonResponse(['ok' => true, 'id' => $songRequest->getId()]);
    }

    #[Route('/api/my-song-requests', name: 'app_my_song_requests', methods: ['GET'])]
    public function mySongRequests(SongRequestRepository $repo): JsonResponse
    {
        $displayName = $this->getUser()->getDisplayName();
        $all         = $repo->findRecent(50);
        $mine        = array_filter($all, fn($r) => $r->getRequestedBy() === $displayName);

        return new JsonResponse(array_values(array_map(fn($r) => [
            'id'          => $r->getId(),
            'title'       => $r->getTitle(),
            'artist'      => $r->getArtist(),
            'imageUrl'    => $r->getImageUrl(),
            'requestedBy' => $r->getRequestedBy(),
            'requestedAt' => $r->getRequestedAt()->getTimestamp(),
            'status'      => $r->getStatus(),
        ], $mine)));
    }
}
