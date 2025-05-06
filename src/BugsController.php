<?php

require_once __DIR__ . "/../services/CacheService.php";
require_once __DIR__ . "/../services/ResponseService.php";
require_once __DIR__ . "/BitrixController.php";

error_reporting(E_ALL);
ini_set('display_errors', 1);

class BugsController extends BitrixController
{
    private CacheService $cache;
    private ResponseService $response;

    private const STATUS_MAPPING = [
        1189 => 'Open',
        1191 => 'In Progress',
        1193 => 'Resolved',
        1195 => 'Closed',
        1219 => 'Reopened',
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

    private const SEVERITY_MAPPING = [
        1211 => 'Critical',
        1213 => 'Major',
        1215 => 'Minor',
        1217 => 'Trivial',
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
        // $cacheKey = $method . '_' . ($_GET['endpoint'] ?? 'bugs') . ($id ?? 'all') . (isset($_GET['page']) ? '_page_' . $_GET['page'] : '');

        // $cachedData = $this->cache->get($cacheKey);
        // if ($cachedData !== null && $method === 'GET') {
        //     $this->response->sendSuccess(200, $cachedData);
        //     return;
        // }

        try {
            switch ($method) {
                case 'GET':
                    $data = $id ? $this->getBug($id) : $this->getAllBugs();
                    break;

                case 'POST':
                    $data = $this->createBug();
                    break;

                case 'PUT':
                    if (!$id) {
                        $this->response->sendError(400, "Bug ID is required for update");
                        return;
                    }
                    $data = $this->updateBug($id);
                    break;

                case 'DELETE':
                    if (!$id) {
                        $this->response->sendError(400, "Bug ID is required for delete");
                        return;
                    }
                    $data = $this->deleteBug($id);
                    break;

                default:
                    $this->response->sendError(405, "Method not allowed");
                    return;
            }

            if ($method === 'GET') {
                // $this->cache->set($cacheKey, $data);
            } else {
                // $this->invalidateCache();
            }

            $this->response->sendSuccess(200, $data);
        } catch (Exception $e) {
            $this->response->sendError(500, "Server error: " . $e->getMessage());
        }
    }

    private function getAllBugs(): array
    {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $bugData = $this->getAllBugsFromSPA($page, $limit);
        $items = $bugData['items'];
        $total = $bugData['total'];

        $transformedBugs = $this->transformData($items);

        return [
            "message" => "Fetched all bugs",
            "bugs" => $transformedBugs,
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total" => $total,
                "totalPages" => ceil($total / $limit),
            ]
        ];
    }

    private function getBug(string $id): array
    {
        $bug = $this->getBugFromSPA($id);

        if (!$bug) {
            $this->response->sendError(404, "Bug not found");
            exit;
        }

        $transformedBug = $this->transformData([$bug])[0];

        return [
            "message" => "Fetched bug with ID $id",
            "bug" => $transformedBug
        ];
    }

    private function createBug(): array
    {
        $input = $this->getInputData();

        $fields = $this->prepareBugFields($input);

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

        $bug = $this->createBugInSPA($fields);
        $transformedBug = $this->transformData([$bug])[0];

        return [
            "message" => "Bug created successfully",
            "bug" => $transformedBug
        ];
    }

    private function getAssignedEmployee(string $category): ?int
    {
        $employeeIds = self::RESPONSIBLE_PERSON_MAPPING[$category] ?? self::RESPONSIBLE_PERSON_MAPPING['Other'];

        if (empty($employeeIds)) {
            return null;
        }

        $leastBugs = PHP_INT_MAX;
        $assignedEmployeeId = null;

        foreach ($employeeIds as $employeeId) {
            $bugCount = count($this->getEmployeeBugs($employeeId));

            if ($bugCount < $leastBugs) {
                $leastBugs = $bugCount;
                $assignedEmployeeId = $employeeId;
            }
        }

        return $assignedEmployeeId;
    }

    private function updateBug(string $id): array
    {
        $existingBug = $this->getBugFromSPA($id);
        if (!$existingBug) {
            $this->response->sendError(404, "Bug not found");
            exit;
        }

        $input = $this->getInputData();

        $fields = $this->prepareBugFields($input);

        if (
            isset($input['category']) &&
            (!isset($existingBug['ufCrm197Category']) ||
                $this->mapValue($existingBug['ufCrm197Category'], self::CATEGORY_MAPPING) != $input['category'])
        ) {

            $assignedById = $this->getAssignedEmployee($input['category']);
            if ($assignedById) {
                $fields['assignedById'] = $assignedById;
            }
        }

        $updatedBug = $this->updateBugInSPA($id, $fields);
        $transformedBug = $this->transformData([$updatedBug])[0];

        return [
            "message" => "Bug with ID $id updated",
            "bug" => $transformedBug
        ];
    }

    private function deleteBug(string $id): array
    {
        $success = $this->deleteBugFromSPA($id);

        if (!$success) {
            $this->response->sendError(404, "Bug not found");
            exit;
        }

        return [
            "message" => "Bug with ID $id deleted",
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

    private function prepareBugFields(array $input): array
    {
        $fields = [
            'ufCrm197Title' => $input['title'] ?? null,
            'ufCrm197Description' => $input['description'] ?? null,
            'ufCrm197Priority' => isset($input['priority']) ? $this->reverseMapPriority($input['priority']) : null,
            'ufCrm197Status' => isset($input['status']) ? $this->reverseMapStatus($input['status']) : null,
            'ufCrm197Attachments' => $input['attachments'] ?? null,
            'ufCrm197PortalUrl' => $input['portal_url'] ?? null,
            'assignedById' => $input['assigned_to'] ?? null,
            'ufCrm197ReportedBy' => $input['reported_to'] ?? null,
            'ufCrm197DateFound' => $input['date_found'] ?? null,
            'ufCrm197Env' => $input['environment'] ?? null,
            'ufCrm197Severity' => $input['severity'] ?? null,
            'ufCrm197StepsToReproduce' => $input['steps_to_reproduce'] ?? null,
            'ufCrm197ExpectedResult' => $input['expected_result'] ?? null,
            'ufCrm197ActualResult' => $input['actual_result'] ?? null,
            'ufCrm197Logs' => $input['logs'] ?? null
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
        foreach ($data as $bug) {
            $transformedBug = [
                'id' => $bug['id'],
                'title' => $bug['ufCrm197Title'] ?? '',
                'description' => $bug['ufCrm197Description'] ?? '',
                'priority' => isset($bug['ufCrm197Priority']) ? $this->mapValue($bug['ufCrm197Priority'], self::PRIORITY_MAPPING) : '',
                'severity' => isset($bug['ufCrm197Severity']) ? $this->mapValue($bug['ufCrm197Severity'], self::SEVERITY_MAPPING) : '',
                'category' => isset($bug['ufCrm197Category']) ? $this->mapValue($bug['ufCrm197Category'], self::CATEGORY_MAPPING) : '',
                'status' => isset($bug['ufCrm197Status']) ? $this->mapValue($bug['ufCrm197Status'], self::STATUS_MAPPING) : '',
                'reported_by' => $bug['ufCrm197ReportedBy'] ?? '',
                'environment' => $bug['ufCrm197Env'] ?? '',
                'attachments' => $bug['ufCrm197Attachments'] ?? '',
                'portalUrl' => $bug['ufCrm197PortalUrl'] ?? '',
                'date_found' => $bug['ufCrm197DateFound'] ?? '',
                'steps_to_reproduce' => $bug['ufCrm197StepsToReproduce'] ?? '',
                'expected_result' => $bug['ufCrm197ExpectedResult'] ?? '',
                'actual_result' =>  $bug['ufCrm197ActualResult'] ?? '',
                'logs' => $bug['logs'] ?? '',
                'assignedTo' => isset($bug['assignedById']) ? $this->mapValue($bug['assignedById'], self::EMPLOYEE_MAPPING) : '',
                'createdTime' => isset($bug['createdTime']) ? $this->formatDate($bug['createdTime']) : '',
                'updatedTime' => isset($bug['updatedTime']) ? $this->formatDate($bug['updatedTime']) : '',
            ];

            $transformedData[] = $transformedBug;
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
