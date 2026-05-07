<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $host,
        private string $user,
        private string $token,
    ) {}

    /**
     * Returns open Highest-priority tickets, optionally filtered by labels.
     * Keys are ticket keys (e.g. "SUP-123"), values contain key/summary/status/labels.
     *
     * @param string[] $labels  Only return tickets that have at least one of these labels.
     *                          Empty array = no label filter.
     */
    public function getHighestPriorityTickets(array $labels = [], string $account = ''): array
    {
        $jql = 'priority = Highest AND statusCategory != Done';

        if (!empty($account)) {
            $jql .= sprintf(' AND cf[11329] = "%s"', strtoupper($account));
        }

        if (!empty($labels)) {
            $quoted = implode(', ', array_map(fn($l) => '"' . $l . '"', $labels));
            $jql .= ' AND labels in (' . $quoted . ')';
        }

        $response = $this->httpClient->request('GET', rtrim($this->host, '/') . '/rest/api/3/search/jql', [
            'auth_basic' => [$this->user, $this->token],
            'query'      => [
                'jql'    => $jql,
                'fields' => 'summary,status,labels',
                'maxResults' => 50,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Jira API error %d: %s', $response->getStatusCode(), $response->getContent(false)));
        }

        $tickets = [];
        foreach ($response->toArray()['issues'] ?? [] as $issue) {
            $tickets[$issue['key']] = [
                'key'     => $issue['key'],
                'summary' => $issue['fields']['summary'],
                'status'  => $issue['fields']['status']['name'],
                'labels'  => $issue['fields']['labels'] ?? [],
            ];
        }

        return $tickets;
    }
}
