<?php

require_once __DIR__ . "/../crest/crest.php";

class BitrixController
{
    protected $config;
    protected $entityTypeId;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->entityTypeId = $this->config['TICKETS_ENTITY_TYPE_ID'] ?? null;

        if (!$this->entityTypeId) {
            throw new Exception("TICKETS_ENTITY_TYPE_ID is not set in config");
        }
    }

    protected function getAllTicketsFromSPA(int $page = 1, int $limit = 50): array
    {
        $start = ($page - 1) * $limit;

        $result = CRest::call('crm.item.list', [
            'entityTypeId' => $this->entityTypeId,
            'select' => ['*'],
            'start' => $start,
            'limit' => $limit
        ]);

        $items = $result['result']['items'] ?? [];
        $total = $result['total'] ?? 0;

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    protected function getTicketFromSPA(string $id): ?array
    {
        $result = CRest::call('crm.item.get', [
            'entityTypeId' => $this->entityTypeId,
            'id' => $id
        ]);

        return $result['result']['item'] ?? null;
    }

    protected function createTicketInSPA(array $fields): ?array
    {
        $result = CRest::call('crm.item.add', [
            'entityTypeId' => $this->entityTypeId,
            'fields' => $fields
        ]);

        return $result['result']['item'] ?? null;
    }

    protected function updateTicketInSPA(string $id, array $fields): ?array
    {
        $result = CRest::call('crm.item.update', [
            'entityTypeId' => $this->entityTypeId,
            'id' => $id,
            'fields' => $fields
        ]);

        return $result['result']['item'] ?? null;
    }

    protected function deleteTicketFromSPA(string $id): bool
    {
        $result = CRest::call('crm.item.delete', [
            'entityTypeId' => $this->entityTypeId,
            'id' => $id
        ]);

        return isset($result['result']);
    }

    protected function getEmployeeTickets(string $employeeId): array
    {
        $result = CRest::call('crm.item.list', [
            'entityTypeId' => $this->entityTypeId,
            'filter' => [
                'ASSIGNED_BY_ID' => $employeeId
            ],
            'select' => [
                'id',
                'ufCrm197Title',
                'ufCrm197Description',
                'ufCrm197Priority',
                'ufCrm197Category',
                'ufCrm197Status',
                'ufCrm197Attachments',
                'ufCrm197ClientName',
                'ufCrm197ClientEmail',
                'ufCrm197CompanyName'
            ]
        ]);

        return $result['result']['items'] ?? [];
    }

    protected function getAllEmployeesFromBitrix(): array
    {
        $allEmployees = [];
        $start = 0;

        do {
            $response = CRest::call('user.get', [
                'filter' => ['ACTIVE' => 'Y'],
                'select' => ['ID', 'NAME', 'LAST_NAME', 'EMAIL', 'WORK_PHONE', 'UF_USR_1693993295483', 'PERSONAL_PHOTO'],
                'start' => $start
            ]);

            if (!isset($response['result'])) {
                break;
            }

            $allEmployees = array_merge($allEmployees, $response['result']);

            $start = $response['next'] ?? null;
        } while ($start !== null);

        return $allEmployees;
    }
}
