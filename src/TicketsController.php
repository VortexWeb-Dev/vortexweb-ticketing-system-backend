<?php

require_once __DIR__ . "/../services/CacheService.php";
require_once __DIR__ . "/../services/ResponseService.php";
require_once __DIR__ . "/BitrixController.php";

error_reporting(E_ALL);
ini_set('display_errors', 1);

class TicketsController extends BitrixController
{
    private CacheService $cache;
    private ResponseService $response;

    private const STATUS_MAPPING = [
        1189 => 'Open',
        1191 => 'In Progress',
        1193 => 'Resolved',
        1195 => 'Closed',
    ];

    private const PRIORITY_MAPPING = [
        1173 => 'Low',
        1175 => 'Medium',
        1177 => 'High',
    ];

    private const CATEGORY_MAPPING = [
        1179 => 'Technical Support',
        1181 => 'Billing',
        1183 => 'Feature Request',
        1185 => 'Account Access',
        1187 => 'Other',
    ];

    private const STAGE_MAPPING = [
        'Open' => 'DT1430_223:NEW',
        'In Progress' => 'DT1430_223:PREPARATION',
        'Resolved' => 'DT1430_223:CLIENT',
        'Closed' => 'DT1430_223:UC_W759PK',
    ];

    private const EMPLOYEE_MAPPING = [
        1 => 'Vortexweb (Ishika)',
        55 => 'Chetan',
        201 => 'Aaryan',
        205 => 'Muhammed Fasil K',
        229 => 'Rohan Pachauri',
        235 => 'Ajzal',
        245 => 'Devi Krishna',
        273 => 'Deshraj Singh',
    ];

    private const RESPONSIBLE_PERSON_MAPPING = [
        'Technical Support' => [
            1,   // Vortexweb (Ishika)
        ],
        'Billing' => [
            1,   // Vortexweb (Ishika)
            55,  // Chetan
        ],
        'Feature Request' => [
            1,   // Vortexweb (Ishika)
            201, // Aaryan
            205, // Muhammed Fasil K
            229, // Rohan Pachauri
            235, // Ajzal
            245, // Devi Krishna
            273, // Deshraj Singh
        ],
        'Account Access' => [
            1, // Vortexweb (Ishika)
        ],
        'Other' => [
            1, // Vortexweb (Ishika)
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->cache = new CacheService(300);
        $this->response = new ResponseService();
    }

    public function processRequest(string $method, ?string $id): void
    {
        // $cacheKey = $method . '_' . ($_GET['endpoint'] ?? 'tickets') . ($id ?? 'all') . (isset($_GET['page']) ? '_page_' . $_GET['page'] : '');

        // $cachedData = $this->cache->get($cacheKey);
        // if ($cachedData !== null && $method === 'GET') {
        //     $this->response->sendSuccess(200, $cachedData);
        //     return;
        // }

        try {
            switch ($method) {
                case 'GET':
                    $data = $id ? $this->getTicket($id) : $this->getAllTickets();
                    break;

                case 'POST':
                    $data = $this->createTicket();
                    break;

                case 'PUT':
                    if (!$id) {
                        $this->response->sendError(400, "Ticket ID is required for update");
                        return;
                    }
                    $data = $this->updateTicket($id);
                    break;

                case 'DELETE':
                    if (!$id) {
                        $this->response->sendError(400, "Ticket ID is required for delete");
                        return;
                    }
                    $data = $this->deleteTicket($id);
                    break;

                default:
                    $this->response->sendError(405, "Method not allowed");
                    return;
            }

            if ($method === 'GET') {
                // $this->cache->set($cacheKey, $data);
            } else {
                $this->invalidateCache();
            }

            $this->response->sendSuccess(200, $data);
        } catch (Exception $e) {
            $this->response->sendError(500, "Server error: " . $e->getMessage());
        }
    }

    private function getAllTickets(): array
    {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $ticketData = $this->getAllTicketsFromSPA($page, $limit);
        $items = $ticketData['items'];
        $total = $ticketData['total'];

        $transformedTickets = $this->transformData($items);

        return [
            "message" => "Fetched all tickets",
            "tickets" => $transformedTickets,
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total" => $total,
                "totalPages" => ceil($total / $limit),
            ]
        ];
    }

    private function getTicket(string $id): array
    {
        $ticket = $this->getTicketFromSPA($id);

        if (!$ticket) {
            $this->response->sendError(404, "Ticket not found");
            exit;
        }

        $transformedTicket = $this->transformData([$ticket])[0];

        return [
            "message" => "Fetched ticket with ID $id",
            "ticket" => $transformedTicket
        ];
    }

    private function createTicket(): array
    {
        $input = $this->getInputData();

        $fields = $this->prepareTicketFields($input);

        if (empty($fields['ufCrm197Title']) || empty($fields['ufCrm197ClientName'])) {
            $this->response->sendError(400, "Title and Client Name are required");
            exit;
        }

        if (isset($input['category']) && !isset($fields['assignedById'])) {
            $assignedById = $this->getAssignedEmployee($input['category']);
            if ($assignedById) {
                $fields['assignedById'] = $assignedById;
            }
        }

        $ticket = $this->createTicketInSPA($fields);
        $transformedTicket = $this->transformData([$ticket])[0];

        return [
            "message" => "Ticket created successfully",
            "ticket" => $transformedTicket
        ];
    }

    private function getAssignedEmployee(string $category): ?int
    {
        $employeeIds = self::RESPONSIBLE_PERSON_MAPPING[$category] ?? self::RESPONSIBLE_PERSON_MAPPING['Other'];

        if (empty($employeeIds)) {
            return null;
        }

        $leastTickets = PHP_INT_MAX;
        $assignedEmployeeId = null;

        foreach ($employeeIds as $employeeId) {
            $ticketCount = count($this->getEmployeeTickets($employeeId));

            if ($ticketCount < $leastTickets) {
                $leastTickets = $ticketCount;
                $assignedEmployeeId = $employeeId;
            }
        }

        return $assignedEmployeeId;
    }

    private function updateTicket(string $id): array
    {
        $existingTicket = $this->getTicketFromSPA($id);
        if (!$existingTicket) {
            $this->response->sendError(404, "Ticket not found");
            exit;
        }

        $input = $this->getInputData();

        $fields = $this->prepareTicketFields($input);

        if (
            isset($input['category']) &&
            (!isset($existingTicket['ufCrm197Category']) ||
                $this->mapValue($existingTicket['ufCrm197Category'], self::CATEGORY_MAPPING) != $input['category'])
        ) {

            $assignedById = $this->getAssignedEmployee($input['category']);
            if ($assignedById) {
                $fields['assignedById'] = $assignedById;
            }
        }

        $updatedTicket = $this->updateTicketInSPA($id, $fields);
        $transformedTicket = $this->transformData([$updatedTicket])[0];

        return [
            "message" => "Ticket with ID $id updated",
            "ticket" => $transformedTicket
        ];
    }

    private function deleteTicket(string $id): array
    {
        $success = $this->deleteTicketFromSPA($id);

        if (!$success) {
            $this->response->sendError(404, "Ticket not found");
            exit;
        }

        return [
            "message" => "Ticket with ID $id deleted",
            "success" => true
        ];
    }

    private function getInputData(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $this->response->sendError(400, "Invalid JSON input");
            exit;
        }
        return $input;
    }

    private function prepareTicketFields(array $input): array
    {
        $fields = [
            'ufCrm197Title' => $input['title'] ?? null,
            'ufCrm197Description' => $input['description'] ?? null,
            'ufCrm197Priority' => isset($input['priority']) ? $this->reverseMapPriority($input['priority']) : null,
            'ufCrm197Category' => isset($input['category']) ? $this->reverseMapCategory($input['category']) : null,
            'ufCrm197Status' => isset($input['status']) ? $this->reverseMapStatus($input['status']) : null,
            'ufCrm197Attachments' => $input['attachments'] ?? null,
            'ufCrm197Comments' => $input['comments'] ?? null,
            'ufCrm197PlannedHours' => $input['planned_hours'] ?? null,
            'ufCrm197ClientName' => $input['client_name'] ?? null,
            'ufCrm197ClientEmail' => $input['client_email'] ?? null,
            'ufCrm197CompanyName' => $input['company_name'] ?? null,
            'ufCrm197PortalUrl' => $input['portal_url'] ?? null,
            'assignedById' => $input['assigned_to'] ?? null
        ];

        if (isset($input['status'])) {
            $fields['stageId'] = $this->getStageFromStatus($input['status']);
        }

        return array_filter($fields, function ($value) {
            return $value !== null;
        });
    }

    private function transformData(array $data): array
    {
        $transformedData = [];
        foreach ($data as $ticket) {
            $transformedTicket = [
                'id' => $ticket['id'],
                'title' => $ticket['ufCrm197Title'] ?? '',
                'description' => $ticket['ufCrm197Description'] ?? '',
                'priority' => isset($ticket['ufCrm197Priority']) ? $this->mapValue($ticket['ufCrm197Priority'], self::PRIORITY_MAPPING) : '',
                'category' => isset($ticket['ufCrm197Category']) ? $this->mapValue($ticket['ufCrm197Category'], self::CATEGORY_MAPPING) : '',
                'status' => isset($ticket['ufCrm197Status']) ? $this->mapValue($ticket['ufCrm197Status'], self::STATUS_MAPPING) : '',
                'attachments' => $ticket['ufCrm197Attachments'] ?? '',
                'comments' => $ticket['ufCrm197Comments'] ?? '',
                'plannedHours' => $ticket['ufCrm197PlannedHours'] ?? '',
                'clientName' => $ticket['ufCrm197ClientName'] ?? '',
                'companyName' => $ticket['ufCrm197CompanyName'] ?? '',
                'clientEmail' => $ticket['ufCrm197ClientEmail'] ?? '',
                'portalUrl' => $ticket['ufCrm197PortalUrl'] ?? '',
                'assignedTo' => isset($ticket['assignedById']) ? $this->mapValue($ticket['assignedById'], self::EMPLOYEE_MAPPING) : '',
                'createdTime' => isset($ticket['createdTime']) ? $this->formatDate($ticket['createdTime']) : '',
                'updatedTime' => isset($ticket['updatedTime']) ? $this->formatDate($ticket['updatedTime']) : '',
            ];

            $transformedData[] = $transformedTicket;
        }

        return $transformedData;
    }

    private function formatDate(string $date): string
    {
        try {
            $dateObj = new DateTime($date);
            return $dateObj->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }

    private function mapValue(string $id, array $mapping): string
    {
        return $mapping[$id] ?? 'Unknown';
    }

    private function reverseMapPriority(string $label): ?int
    {
        $mapping = array_flip(self::PRIORITY_MAPPING);
        return $mapping[$label] ?? null;
    }

    private function reverseMapStatus(string $label): ?int
    {
        $mapping = array_flip(self::STATUS_MAPPING);
        return $mapping[$label] ?? null;
    }

    private function reverseMapCategory(string $label): ?int
    {
        $mapping = array_flip(self::CATEGORY_MAPPING);
        return $mapping[$label] ?? null;
    }

    private function getStageFromStatus(string $status): ?string
    {
        return self::STAGE_MAPPING[$status] ?? null;
    }

    private function invalidateCache(): void
    {
        $this->cache->delete('GET_all');
    }
}
