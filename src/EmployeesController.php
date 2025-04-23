<?php

require_once __DIR__ . "/../services/CacheService.php";
require_once __DIR__ . "/../services/ResponseService.php";
require_once __DIR__ . "/BitrixController.php";

error_reporting(E_ALL);
ini_set('display_errors', 1);

class EmployeesController extends BitrixController
{
    private CacheService $cache;
    private ResponseService $response;

    public function __construct()
    {
        parent::__construct();
        $this->cache = new CacheService(300);
        $this->response = new ResponseService();
    }

    public function processRequest(string $method, ?string $id): void
    {
        $cacheKey = $method . '_' . ($_GET['endpoint'] ?? 'employees') . ($id ?? 'all') . (isset($_GET['page']) ? '_page_' . $_GET['page'] : '');

        $cachedData = $this->cache->get($cacheKey);
        if ($cachedData !== null && $method === 'GET') {
            $this->response->sendSuccess(200, $cachedData);
            return;
        }

        try {
            switch ($method) {
                case 'GET':
                    $data = $this->getAllEmployees();
                    break;

                default:
                    $this->response->sendError(405, "Method not allowed");
                    return;
            }

            if ($method === 'GET') {
                $this->cache->set($cacheKey, $data);
            } else {
                $this->invalidateCache();
            }

            $this->response->sendSuccess(200, $data);
        } catch (Exception $e) {
            $this->response->sendError(500, "Server error: " . $e->getMessage());
        }
    }

    private function getAllEmployees(): array
    {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $employeeData = $this->getAllEmployeesFromBitrix($page, $limit);
        $items = $employeeData;
        $total = count($employeeData);

        // Attach tickets to each employee
        foreach ($items as &$item) {
            $item['tickets'] = $this->getEmployeeTickets($item['ID']);
        }

        // Transform the updated items
        $transformedEmployees = $this->transformData($items);

        return [
            "message" => "Fetched all employees",
            "employees" => $transformedEmployees,
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total" => $total,
                "totalPages" => ceil($total / $limit),
            ]
        ];
    }

    private function transformData(array $data): array
    {
        $transformedData = [];
        foreach ($data as $employee) {
            $transformedEmployee = [
                'id' => $employee['ID'],
                'fullname' => trim($employee['NAME'] . ' ' . $employee['LAST_NAME']) ?? '',
                'email' => $employee['EMAIL'] ?? '',
                'phone' => $employee['WORK_PHONE'] ?? '',
                'position' => $employee['UF_USR_1693993295483'] ?? '',
                'photo' => $employee['PERSONAL_PHOTO'] ?? '',
                'tickets' => $employee['tickets'] ?? [],
            ];

            $transformedData[] = $transformedEmployee;
        }

        return $transformedData;
    }

    private function invalidateCache(): void
    {
        $this->cache->delete('GET_all');
    }
}
