<?php
/**
 * WHOIS API for domain registration information
 * Checks domain registration dates, expiry, registrar etc.
 */

require_once 'config.php';
require_once 'functions.php';
require_once 'whois_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'check':
            // Check WHOIS for a single domain
            $domainId = (int)($_POST['domain_id'] ?? 0);
            if ($domainId <= 0) {
                throw new Exception('Не указан домен');
            }
            $result = checkDomainWhois($pdo, $userId, $domainId);
            echo json_encode($result);
            break;
            
        case 'check_single':
            // Check WHOIS for one domain by ID (sequential processing)
            $domainId = (int)($_POST['domain_id'] ?? 0);
            if ($domainId <= 0) {
                throw new Exception('Не указан домен');
            }
            $result = checkDomainWhois($pdo, $userId, $domainId);
            echo json_encode($result);
            break;
            
        case 'bulk_check':
            // Check WHOIS for multiple domains (returns summary)
            $domainIds = $_POST['domain_ids'] ?? [];
            if (is_string($domainIds)) {
                $domainIds = json_decode($domainIds, true);
            }
            if (empty($domainIds)) {
                throw new Exception('Не выбраны домены');
            }
            $result = bulkCheckWhois($pdo, $userId, $domainIds);
            echo json_encode($result);
            break;
            
        case 'list':
            // Get list of domains with WHOIS data
            $filter = $_GET['filter'] ?? 'all';
            $result = getDomainsWithWhois($pdo, $userId, $filter);
            echo json_encode(['success' => true, 'domains' => $result]);
            break;
            
        case 'expiring':
            // Get domains expiring within N days
            $days = (int)($_GET['days'] ?? 30);
            $result = getExpiringDomains($pdo, $userId, $days);
            echo json_encode(['success' => true, 'domains' => $result, 'days' => $days]);
            break;
            
        case 'stats':
            // Get WHOIS statistics
            $result = getWhoisStats($pdo, $userId);
            echo json_encode(['success' => true, 'stats' => $result]);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
