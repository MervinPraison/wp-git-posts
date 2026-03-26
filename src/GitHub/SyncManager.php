<?php
namespace PraisonPress\GitHub;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use PraisonPress\Git\GitManager;

/**
 * GitHub Sync Manager
 * Handles synchronization between local content and remote GitHub repository
 */
class SyncManager {
    
    private $gitManager;
    private $contentDir;
    private $repoUrl;
    private $mainBranch;
    
    /**
     * Constructor
     */
    public function __construct($repoUrl = null, $mainBranch = 'main') {
        $this->gitManager = new GitManager();
        $this->contentDir = PRAISON_CONTENT_DIR;
        $this->repoUrl = $repoUrl;
        $this->mainBranch = $mainBranch;
    }
    
    /**
     * Clone repository if content directory is empty or not a git repo
     * @return array Result with success status and message
     */
    public function cloneRepository() {
        if (empty($this->repoUrl)) {
            return [
                'success' => false,
                'message' => 'No remote repository configured',
            ];
        }
        
        // Check if content directory exists and is a git repo
        if (is_dir($this->contentDir . '/.git')) {
            return [
                'success' => true,
                'message' => 'Repository already cloned',
                'already_exists' => true,
            ];
        }
        
        // If content directory exists but is not a git repo, we need to handle it carefully
        if (is_dir($this->contentDir)) {
            // Check if directory is empty
            $files = scandir($this->contentDir);
            $files = array_diff($files, ['.', '..', '.gitignore']);
            
            if (!empty($files)) {
                return [
                    'success' => false,
                    'message' => 'Content directory exists with files. Please backup and remove existing files or initialize git manually.',
                ];
            }
        } else {
            // Create content directory using WordPress filesystem API
            if (!wp_mkdir_p($this->contentDir)) {
                return [
                    'success' => false,
                    'message' => 'Failed to create content directory',
                ];
            }
        }
        
        // Clone the repository
        $parentDir = dirname($this->contentDir);
        $dirName = basename($this->contentDir);
        
        $oldDir = getcwd();
        chdir($parentDir);
        
        exec('git clone ' . escapeshellarg($this->repoUrl) . ' ' . escapeshellarg($dirName) . ' 2>&1', $output, $return);
        
        chdir($oldDir);
        
        if ($return === 0) {
            return [
                'success' => true,
                'message' => 'Successfully cloned repository',
                'output' => implode("\n", $output),
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to clone repository: ' . implode("\n", $output),
        ];
    }
    
    /**
     * Set up remote repository
     * @return bool Success status
     */
    public function setupRemote() {
        if (empty($this->repoUrl)) {
            return false;
        }
        
        $oldDir = getcwd();
        chdir($this->contentDir);
        
        // Check if remote already exists
        exec('git remote -v 2>&1', $output, $return);
        $hasOrigin = false;
        
        foreach ($output as $line) {
            if (strpos($line, 'origin') !== false) {
                $hasOrigin = true;
                break;
            }
        }
        
        if (!$hasOrigin) {
            // Add remote
            exec('git remote add origin ' . escapeshellarg($this->repoUrl) . ' 2>&1', $output, $return);
        } else {
            // Update remote URL
            exec('git remote set-url origin ' . escapeshellarg($this->repoUrl) . ' 2>&1', $output, $return);
        }
        
        chdir($oldDir);
        return $return === 0;
    }
    
    /**
     * Pull changes from remote repository
     * @return array Result with success status and message
     */
    public function pullFromRemote() {
        if (empty($this->repoUrl)) {
            return [
                'success' => false,
                'message' => 'No remote repository configured',
            ];
        }
        
        $oldDir = getcwd();
        chdir($this->contentDir);
        
        // Fetch from remote
        exec('git fetch origin ' . escapeshellarg($this->mainBranch) . ' 2>&1', $fetchOutput, $fetchReturn);
        
        if ($fetchReturn !== 0) {
            chdir($oldDir);
            return [
                'success' => false,
                'message' => 'Failed to fetch from remote: ' . implode("\n", $fetchOutput),
            ];
        }
        
        // Check if there are changes
        exec('git rev-list HEAD..origin/' . escapeshellarg($this->mainBranch) . ' --count 2>&1', $countOutput, $countReturn);
        $changesCount = isset($countOutput[0]) ? (int)$countOutput[0] : 0;
        
        if ($changesCount === 0) {
            chdir($oldDir);
            return [
                'success' => true,
                'message' => 'Already up to date',
                'changes' => 0,
            ];
        }
        
        // Pull changes
        exec('git pull origin ' . escapeshellarg($this->mainBranch) . ' 2>&1', $pullOutput, $pullReturn);
        
        chdir($oldDir);
        
        if ($pullReturn === 0) {
            return [
                'success' => true,
                'message' => 'Successfully pulled ' . $changesCount . ' change(s) from remote',
                'changes' => $changesCount,
                'output' => implode("\n", $pullOutput),
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to pull from remote: ' . implode("\n", $pullOutput),
        ];
    }
    
    /**
     * Push changes to remote repository
     * @return array Result with success status and message
     */
    public function pushToRemote() {
        if (empty($this->repoUrl)) {
            return [
                'success' => false,
                'message' => 'No remote repository configured',
            ];
        }
        
        $oldDir = getcwd();
        chdir($this->contentDir);
        
        // Push to remote
        exec('git push origin ' . escapeshellarg($this->mainBranch) . ' 2>&1', $output, $return);
        
        chdir($oldDir);
        
        if ($return === 0) {
            return [
                'success' => true,
                'message' => 'Successfully pushed to remote',
                'output' => implode("\n", $output),
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to push to remote: ' . implode("\n", $output),
        ];
    }
    
    /**
     * Check sync status
     * @return array Status information
     */
    public function getSyncStatus() {
        if (empty($this->repoUrl)) {
            return [
                'configured' => false,
                'message' => 'No remote repository configured',
            ];
        }
        
        $oldDir = getcwd();
        chdir($this->contentDir);
        
        // Fetch from remote (quietly)
        exec('git fetch origin ' . escapeshellarg($this->mainBranch) . ' 2>&1', $fetchOutput, $fetchReturn);
        
        if ($fetchReturn !== 0) {
            chdir($oldDir);
            return [
                'configured' => true,
                'connected' => false,
                'message' => 'Cannot connect to remote repository',
            ];
        }
        
        // Check for incoming changes
        exec('git rev-list HEAD..origin/' . escapeshellarg($this->mainBranch) . ' --count 2>&1', $incomingOutput);
        $incomingChanges = isset($incomingOutput[0]) ? (int)$incomingOutput[0] : 0;
        
        // Check for outgoing changes
        exec('git rev-list origin/' . escapeshellarg($this->mainBranch) . '..HEAD --count 2>&1', $outgoingOutput);
        $outgoingChanges = isset($outgoingOutput[0]) ? (int)$outgoingOutput[0] : 0;
        
        // Get last sync time
        exec('git log -1 --format=%at origin/' . escapeshellarg($this->mainBranch) . ' 2>&1', $timeOutput);
        $lastSync = isset($timeOutput[0]) ? (int)$timeOutput[0] : 0;
        
        chdir($oldDir);
        
        return [
            'configured' => true,
            'connected' => true,
            'incoming_changes' => $incomingChanges,
            'outgoing_changes' => $outgoingChanges,
            'last_sync' => $lastSync,
            'last_sync_date' => $lastSync > 0 ? gmdate('Y-m-d H:i:s', $lastSync) : 'Never',
            'up_to_date' => ($incomingChanges === 0 && $outgoingChanges === 0),
        ];
    }
    
    /**
     * Handle webhook push event
     * @param array $payload GitHub webhook payload
     * @return array Result
     */
    public function handleWebhookPush($payload) {
        // Verify it's a push to the main branch
        if (!isset($payload['ref']) || $payload['ref'] !== 'refs/heads/' . $this->mainBranch) {
            return [
                'success' => false,
                'message' => 'Not a push to main branch',
            ];
        }
        
        $oldDir = getcwd();
        chdir($this->contentDir);
        
        // Record HEAD before pull for diff comparison.
        exec('git rev-parse HEAD 2>&1', $beforeOutput);
        $beforeRef = isset($beforeOutput[0]) ? trim($beforeOutput[0]) : '';
        
        chdir($oldDir);
        
        // Pull changes
        $result = $this->pullFromRemote();
        
        if ($result['success'] && !empty($beforeRef)) {
            // Detect changed files and do incremental index updates.
            $this->processChangedFiles($beforeRef);
        }
        
        return $result;
    }
    
    /**
     * Process changed files after a pull — incremental index updates.
     *
     * @param string $beforeRef Git ref before the pull.
     */
    private function processChangedFiles(string $beforeRef): void {
        $oldDir = getcwd();
        chdir($this->contentDir);
        
        // Get list of changed files with status: A(dded), M(odified), D(eleted), R(enamed).
        exec('git diff --name-status ' . escapeshellarg($beforeRef) . '..HEAD 2>&1', $diffOutput, $diffReturn);
        
        chdir($oldDir);
        
        if ($diffReturn !== 0 || empty($diffOutput)) {
            return;
        }
        
        // Process each changed file.
        foreach ($diffOutput as $line) {
            // Format: "A\tlyrics/my-song.md" or "R100\tlyrics/old.md\tlyrics/new.md"
            $parts = preg_split('/\t+/', $line);
            if (count($parts) < 2) {
                continue;
            }
            
            $status = $parts[0];
            $file   = $parts[1];
            
            // Only process .md files (skip _index.json, .gitignore, etc).
            if (substr($file, -3) !== '.md' || strpos(basename($file), '_') === 0) {
                continue;
            }
            
            // Extract post type from path (first directory component).
            $pathParts = explode('/', $file);
            if (count($pathParts) < 2) {
                continue;
            }
            $type = $pathParts[0];
            
            $fullPath = $this->contentDir . '/' . $file;
            $slug     = pathinfo(basename($file), PATHINFO_FILENAME);
            
            if ($status === 'D') {
                // Deleted file — remove from index.
                \PraisonPress\Index\IndexManager::remove($type, $slug);
            } elseif (strpos($status, 'R') === 0 && isset($parts[2])) {
                // Renamed: remove old, add new.
                $oldSlug = pathinfo(basename($parts[1]), PATHINFO_FILENAME);
                \PraisonPress\Index\IndexManager::remove($type, $oldSlug);
                
                $newFile = $this->contentDir . '/' . $parts[2];
                $newSlug = pathinfo(basename($parts[2]), PATHINFO_FILENAME);
                $newType = explode('/', $parts[2])[0] ?? $type;
                \PraisonPress\Index\IndexManager::addOrUpdate($newType, $newSlug, $newFile);
            } else {
                // Added or Modified — upsert in index.
                \PraisonPress\Index\IndexManager::addOrUpdate($type, $slug, $fullPath);
            }
        }
        
        if (get_option('praisonpress_qm_logging')) {
            do_action('qm/info', sprintf(
                '[PraisonPress] Webhook: processed %d file change(s) from git pull',
                count($diffOutput)
            ));
        }
    }
}
