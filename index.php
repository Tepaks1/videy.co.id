<?php

// Configuration
class Config {
    const UPLOAD_DIR = 'uploads/';
    const THUMB_DIR = 'uploads/thumbnails/';
    const CACHE_DIR = 'cache/';
    const CHUNKS_DIR = 'cache/chunks/';
    const CACHE_DURATION = 3600;
    const MAX_FILE_SIZE = 1048576000; // 1000MB
    const ALLOWED_TYPES = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/webm' => 'webm'
    ];
    const CHUNK_SIZE = 1048576; // 1MB chunks
    const DB_HOST = 'localhost';
    const DB_NAME = 'videy_videy';
    const DB_USER = 'videy_videy';
    const DB_PASS = 'Videycoid123@';
}

// Create required directories
foreach ([Config::UPLOAD_DIR, Config::THUMB_DIR, Config::CACHE_DIR, Config::CHUNKS_DIR] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

class CacheManager {
    private function getCacheFilePath($key) {
        return Config::CACHE_DIR . md5($key) . '.json';
    }

    public function set($key, $data, $duration = null) {
        if ($duration === null) {
            $duration = Config::CACHE_DURATION;
        }

        $cacheData = [
            'expires' => time() + $duration,
            'data' => $data
        ];

        return file_put_contents(
            $this->getCacheFilePath($key),
            json_encode($cacheData),
            LOCK_EX
        );
    }

    public function get($key) {
        $filepath = $this->getCacheFilePath($key);
        
        if (!file_exists($filepath)) {
            return null;
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            return null;
        }

        $cacheData = json_decode($content, true);
        if (!$cacheData || time() > $cacheData['expires']) {
            @unlink($filepath);
            return null;
        }

        return $cacheData['data'];
    }

    public function delete($key) {
        $filepath = $this->getCacheFilePath($key);
        if (file_exists($filepath)) {
            return unlink($filepath);
        }
        return true;
    }
}

class ChunkManager {
    private $cache;

    public function __construct() {
        $this->cache = new CacheManager();
    }

    public function createChunks($filePath, $videoId) {
        $chunkSize = Config::CHUNK_SIZE;
        $fileSize = filesize($filePath);
        $numChunks = ceil($fileSize / $chunkSize);
        $chunkInfo = [];

        $inputHandle = fopen($filePath, 'rb');
        
        for ($i = 0; $i < $numChunks; $i++) {
            $chunkPath = Config::CHUNKS_DIR . $videoId . '_chunk_' . $i;
            $outputHandle = fopen($chunkPath, 'wb');
            
            $currentChunkSize = min($chunkSize, $fileSize - ($i * $chunkSize));
            $chunkData = fread($inputHandle, $currentChunkSize);
            fwrite($outputHandle, $chunkData);
            fclose($outputHandle);

            $chunkInfo[] = [
                'path' => $chunkPath,
                'size' => $currentChunkSize,
                'offset' => $i * $chunkSize
            ];
        }

        fclose($inputHandle);

        $this->cache->set('chunks_' . $videoId, [
            'totalSize' => $fileSize,
            'chunks' => $chunkInfo
        ]);

        return $chunkInfo;
    }

    public function streamChunks($videoId, $start, $end, $fileSize) {
        $chunkInfo = $this->cache->get('chunks_' . $videoId);
        if (!$chunkInfo) {
            return false;
        }

        $startChunk = floor($start / Config::CHUNK_SIZE);
        $endChunk = floor($end / Config::CHUNK_SIZE);
        
        $remainingBytes = $end - $start + 1;
        $currentPosition = $start;

        for ($i = $startChunk; $i <= $endChunk; $i++) {
            $chunkPath = Config::CHUNKS_DIR . $videoId . '_chunk_' . $i;
            if (!file_exists($chunkPath)) {
                return false;
            }

            $chunkHandle = fopen($chunkPath, 'rb');
            $chunkOffset = $currentPosition - ($i * Config::CHUNK_SIZE);
            if ($chunkOffset > 0) {
                fseek($chunkHandle, $chunkOffset);
            }

            $bytesToRead = min(Config::CHUNK_SIZE - $chunkOffset, $remainingBytes);
            echo fread($chunkHandle, $bytesToRead);
            flush();
            fclose($chunkHandle);

            $currentPosition += $bytesToRead;
            $remainingBytes -= $bytesToRead;

            if ($remainingBytes <= 0) {
                break;
            }
        }

        return true;
    }

    public function cleanupChunks($videoId) {
        $chunkInfo = $this->cache->get('chunks_' . $videoId);
        if ($chunkInfo) {
            foreach ($chunkInfo['chunks'] as $chunk) {
                if (file_exists($chunk['path'])) {
                    unlink($chunk['path']);
                }
            }
            $this->cache->delete('chunks_' . $videoId);
        }
    }
}

class DatabaseManager {
    private $conn;
    private $cache;

    public function __construct() {
        $this->cache = new CacheManager();
        try {
            $this->conn = new PDO(
                "mysql:host=" . Config::DB_HOST . ";dbname=" . Config::DB_NAME,
                Config::DB_USER,
                Config::DB_PASS,
                [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"]
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->initializeTable();
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    private function initializeTable() {
        $sql = "CREATE TABLE IF NOT EXISTS videos (
            id VARCHAR(9) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            path VARCHAR(255) NOT NULL,
            size BIGINT NOT NULL,
            type VARCHAR(50) NOT NULL,
            upload_date DATETIME NOT NULL,
            views INT DEFAULT 0,
            share_link VARCHAR(9) UNIQUE NOT NULL,
            INDEX idx_share_link (share_link)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $this->conn->exec($sql);
    }

    public function addVideo($videoData) {
        $sql = "INSERT INTO videos (id, name, filename, path, size, type, upload_date, views, share_link) 
                VALUES (:id, :name, :filename, :path, :size, :type, :upload_date, :views, :share_link)";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':id' => $videoData['id'],
                ':name' => $videoData['name'],
                ':filename' => $videoData['filename'],
                ':path' => $videoData['path'],
                ':size' => $videoData['size'],
                ':type' => $videoData['type'],
                ':upload_date' => $videoData['upload_date'],
                ':views' => $videoData['views'],
                ':share_link' => $videoData['id']
            ]);

            if ($result) {
                $this->cache->set('video_' . $videoData['id'], $videoData);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            return false;
        }
    }

    public function isIdUnique($id) {
        $sql = "SELECT COUNT(*) FROM videos WHERE id = :id OR share_link = :id";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':id' => $id]);
            return $stmt->fetchColumn() === 0;
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            return false;
        }
    }

    public function getVideo($id) {
        $cachedVideo = $this->cache->get('video_' . $id);
        if ($cachedVideo !== null) {
            return $cachedVideo;
        }

        $sql = "SELECT * FROM videos WHERE id = :id OR share_link = :id";
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':id' => $id]);
            $video = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($video) {
                $this->cache->set('video_' . $id, $video);
            }
            
            return $video;
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            return null;
        }
    }

    public function updateVideoViews($id) {
        $sql = "UPDATE videos SET views = views + 1 WHERE id = :id OR share_link = :id";
        try {
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([':id' => $id]);
            
            if ($result) {
                $cachedVideo = $this->cache->get('video_' . $id);
                if ($cachedVideo) {
                    $cachedVideo['views']++;
                    $this->cache->set('video_' . $id, $cachedVideo);
                }
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            return false;
        }
    }
}

class VideoManager {
    private $db;
    private $cache;
    private $chunkManager;

    public function __construct() {
        $this->db = new DatabaseManager();
        $this->cache = new CacheManager();
        $this->chunkManager = new ChunkManager();
    }

    private function generateAlphanumericId() {
        do {
            // Define characters that can be used in the ID
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $id = '';
            
            // Generate a 9-character random string
            for ($i = 0; $i < 9; $i++) {
                $id .= $chars[random_int(0, strlen($chars) - 1)];
            }

            // Ensure at least one letter and one number
            if (!preg_match('/[A-Za-z]/', $id) || !preg_match('/[0-9]/', $id)) {
                continue;
            }
        } while (!$this->db->isIdUnique($id));
        
        return $id;
    }

    public function handleUpload() {
        try {
            if (!isset($_FILES['fileToUpload'])) {
                throw new Exception('No file uploaded.');
            }

            $file = $_FILES['fileToUpload'];
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors = [
                    UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive.',
                    UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
                    UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
                ];
                throw new Exception($errors[$file['error']] ?? 'Unknown upload error.');
            }

            if ($file['size'] > Config::MAX_FILE_SIZE) {
                throw new Exception('File size exceeds 1000MB limit.');
            }

            $fileType = mime_content_type($file['tmp_name']);
            if (!array_key_exists($fileType, Config::ALLOWED_TYPES)) {
                throw new Exception('Invalid video format.');
            }

            $extension = Config::ALLOWED_TYPES[$fileType];
            $videoId = $this->generateAlphanumericId();
            $newFilename = $videoId . '.' . $extension;
            $targetPath = Config::UPLOAD_DIR . $newFilename;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception('Failed to save video.');
            }

            // Create chunks after successful upload
            $this->chunkManager->createChunks($targetPath, $videoId);

            $videoData = [
                'id' => $videoId,
                'name' => htmlspecialchars($file['name']),
                'filename' => $newFilename,
                'path' => $targetPath,
                'size' => $file['size'],
                'type' => $fileType,
                'upload_date' => date('Y-m-d H:i:s'),
                'views' => 0
            ];

            if (!$this->db->addVideo($videoData)) {
                unlink($targetPath);
                $this->chunkManager->cleanupChunks($videoId);
                throw new Exception('Failed to save video information.');
            }

            return ['success' => true, 'share_link' => $videoId];

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function streamVideo($id) {
        $video = $this->db->getVideo($id);
        if (!$video) {
            return false;
        }

        $this->db->updateVideoViews($id);
        
        $size = $video['size'];
        $start = 0;
        $end = $size - 1;

        header('Content-Type: ' . $video['type']);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');
        
        if (isset($_SERVER['HTTP_RANGE'])) {
            list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
            if (strpos($range, ',') !== false) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $start-$end/$size");
                exit;
            }
            
            if ($range[0] == '-') {
                $start = $size - substr($range, 1);
            } else {
                $range = explode('-', $range);
                $start = $range[0];
                $end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
            }

            if ($start > $end || $start > $size - 1 || $end >= $size) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $start-$end/$size");
                exit;
            }
            header('HTTP/1.1 206 Partial Content');
        }

        header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . ($end - $start + 1));
        header('Content-Disposition: inline; filename="' . $video['name'] . '"');
        header('ETag: "' . md5($video['path'] . $size) . '"');

        return $this->chunkManager->streamChunks($video['id'], $start, $end, $size);
    }
}

// Initialize VideoManager
$videoManager = new VideoManager();

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    header('Content-Type: application/json');
    echo json_encode($videoManager->handleUpload());
    exit;
}

// Handle video streaming
if (isset($_GET['stream']) && isset($_GET['id'])) {
    $videoManager->streamVideo($_GET['id']);
    exit;
}

// Initialize variables
$uploadStatus = null;
$videoId = null;

// Check if there's a video ID in the URL
if (isset($_GET['id'])) {
    $videoId = $_GET['id'];
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
      <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    
    <!-- Primary Meta Tags -->
    <title>Videy - Platform Video Hosting Gratis & Profesional | Upload & Bagikan Video dengan Mudah</title>
    <meta name="title" content="Videy - Platform Video Hosting Gratis & Profesional | Upload & Bagikan Video dengan Mudah">
    <meta name="description" content="Videy adalah platform video hosting terpercaya yang menyediakan layanan gratis, cepat, dan aman untuk menyimpan dan membagikan video Anda. Nikmati kemudahan berbagi konten video tanpa batasan.">
    <meta name="keywords" content="videy, video hosting indonesia, platform video gratis, hosting video profesional, upload video, berbagi video, streaming video, platform video terpercaya">
    <meta name="author" content="Videy">
    <meta name="copyright" content="© 2024 Videy. All rights reserved.">
    <meta name="robots" content="index, follow">
    <meta name="language" content="Indonesia">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://videy.co.id/">
    <meta property="og:title" content="Videy - Platform Video Hosting Gratis & Profesional">
    <meta property="og:description" content="Platform video hosting terpercaya untuk menyimpan dan membagikan video Anda dengan mudah, cepat, dan aman.">
    <meta property="og:image" content="https://videy.co.id/template/og.jpg">
    <meta property="og:image:alt" content="Videy Platform Preview">
    <meta property="og:site_name" content="Videy">
    <meta property="og:locale" content="id_ID">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://videy.co.id/">
    <meta name="twitter:title" content="Videy - Platform Video Hosting Gratis & Profesional">
    <meta name="twitter:description" content="Platform video hosting terpercaya untuk menyimpan dan membagikan video Anda dengan mudah, cepat, dan aman.">
    <meta name="twitter:image" content="https://videy.co.id/template/og.jpg">
    <meta name="twitter:image:alt" content="Videy Platform Preview">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="https://videy.co.id/template/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="https://videy.co.id/template/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="https://videy.co.id/template/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    
    <!-- Theme Color -->
    <meta name="theme-color" content="#ffffff">
    <meta name="msapplication-TileColor" content="#ffffff">
    <title>Videy | Free and Simple Video Hosting</title>
    <style>
        /* Critical CSS inlined for faster render */
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;margin:0;padding:0;box-sizing:border-box;display:flex;flex-direction:column;min-height:100vh;background:#fff}nav{padding:15px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center}.logo{font-size:24px;font-weight:700;color:#000;text-decoration:none}.nav-right{display:flex;align-items:center;gap:20px}.upload-nav-btn{background:#f0f0f0;color:#000;padding:8px 16px;border-radius:20px;text-decoration:none;font-size:14px}main{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 20px;text-align:center}

        /* Deferred non-critical CSS */
        .content-wrapper{max-width:600px;width:100%}h1{font-size:36px;margin-bottom:20px;color:#000}.subtitle{font-size:18px;color:#666;margin-bottom:40px}.upload-area{border:2px dashed #ddd;border-radius:10px;padding:40px;text-align:center;cursor:pointer;transition:all .3s ease;margin-bottom:20px}.upload-area:hover{border-color:#000;background:#f8f8f8}.upload-btn{background:#000;color:#fff;padding:12px 24px;border-radius:25px;border:none;font-size:16px;cursor:pointer;transition:all .3s ease}.upload-btn:hover{opacity:.9}.progress-bar{width:100%;height:4px;background:#f0f0f0;border-radius:2px;margin-top:20px;overflow:hidden;opacity:0;transition:opacity .5s}.progress-fill{height:100%;background:#000;width:0;transition:width .3s ease}.error-message{color:#f44;margin-top:10px;display:none}.video-player{width:100%;max-width:800px;margin:20px auto;background:#000;border-radius:8px;overflow:hidden}video{width:100%;height:auto}.video-info{padding:20px;background:#fff;border-radius:8px;margin-top:20px;text-align:left}.share-section{margin-top:20px;display:flex;gap:10px}.share-input{flex:1;padding:10px;border:1px solid #ddd;border-radius:4px}.copy-btn{background:#000;color:#fff;padding:10px 20px;border:none;border-radius:4px;cursor:pointer}footer{padding:20px;text-align:center;color:#666;font-size:14px;border-top:1px solid #eee}.footer-links{margin-top:10px}.footer-links a{color:#666;text-decoration:none;margin:0 10px}#fileUpload{display:none}
        
        /* Added styles for welcome section */
        #welcomeSection {
            display: block;
        }
        body.video-mode #welcomeSection {
            display: none;
        }
.download-btn {
    display: inline-block;
    padding: 10px 20px;
    font-size: 16px;
    font-weight: bold;
    text-align: center;
    text-decoration: none;
    color: #fff;
    background-color: #007bff; /* Warna biru profesional */
    border: none;
    border-radius: 5px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: background-color 0.3s ease, box-shadow 0.3s ease;
}

.download-btn:hover {
    background-color: #0056b3; /* Warna biru lebih gelap untuk efek hover */
    box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
}

.download-btn:active {
    background-color: #004085; /* Warna biru lebih gelap untuk saat tombol ditekan */
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    transform: translateY(1px);
}

    </style>
</head>
<body>
    <nav>
        <!-- Histats.com  START  (aync)-->
<script type="text/javascript">var _Hasync= _Hasync|| [];
_Hasync.push(['Histats.start', '1,4878114,4,0,0,0,00010000']);
_Hasync.push(['Histats.fasi', '1']);
_Hasync.push(['Histats.track_hits', '']);
(function() {
var hs = document.createElement('script'); hs.type = 'text/javascript'; hs.async = true;
hs.src = ('//s10.histats.com/js15_as.js');
(document.getElementsByTagName('head')[0] || document.getElementsByTagName('body')[0]).appendChild(hs);
})();</script>
<noscript><a href="/" target="_blank"><img  src="//sstatic1.histats.com/0.gif?4878114&101" alt="" border="0"></a></noscript>
<!-- Histats.com  END  -->
        <a href="/" class="logo">videy</a>
        <div class="nav-right">
            <a href="/" class="upload-nav-btn">Upload</a>
        </div>
    </nav>

    <main>
        <div class="content-wrapper">
            <div id="welcomeSection">
                <h1>Free and Simple Video Hosting</h1>
                <p class="subtitle">Get started without an account</p>
            </div>
            
            <form id="uploadForm">
                <div class="upload-area" id="dropZone">
                    <input type="file" id="fileUpload" name="fileToUpload" accept="video/*">
                    <button type="button" class="upload-btn">Upload a Video</button>
                    <p style="margin-top:20px;color:#666">or drag and drop a video file</p>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <div class="error-message"></div>
            </form>

            <div id="videoPlayer" style="display:none">
                <div class="video-player">
                    <video controls autoplay loop>
                        <source src="" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
<a href="https://pallorstayingpostcard.com/pj6x5n8x8g?key=1cb05ec0d8df3083b651238b785e4ea7" class="download-btn" download>
    Video Bokep Yang Lain Disini
</a>

                <div class="video-info">
                    <h2 class="video-title"></h2>


                    <div class="share-section">
                        <input type="text" class="share-input" readonly>
                        <button class="copy-btn">Copy Link</button>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <footer>
        <div>Copyright © 2024 TRUE DOMAIN PRIVACY, LLC</div>
        <div class="footer-links">
            <a href="#">Terms of Service</a>
            <a href="#">Report Abuse</a>
        </div>
    </footer>

    <script>
        // Deferred script loading
        document.addEventListener('DOMContentLoaded',()=>{const e=document.getElementById("uploadForm"),t=document.getElementById("fileUpload"),n=document.getElementById("dropZone"),o=document.querySelector(".progress-bar"),a=document.querySelector(".progress-fill"),r=document.querySelector(".error-message"),l=document.querySelector(".upload-btn");function d(e){e.preventDefault(),e.stopPropagation()}function i(){const e=t.files[0];e&&(e.size>1048576e3?s("File size exceeds 1000MB limit"):["video/mp4","video/quicktime","video/x-msvideo","video/webm"].includes(e.type)?c(e):s("Invalid video format. Please upload MP4, MOV, AVI, or WebM files."))}function s(e){o.style.opacity="0",a.style.width="0%",r.textContent=e,r.style.display="block"}function c(e){const t=new FormData;t.append("fileToUpload",e),o.style.opacity="1",r.style.display="none",l.disabled=!0;const n=new XMLHttpRequest;n.upload.addEventListener("progress",e=>{if(e.lengthComputable){const t=e.loaded/e.total*100;a.style.width=t+"%"}}),n.addEventListener("load",()=>{if(200===n.status)try{const e=JSON.parse(n.responseText);e.success?(p(e.share_link),setTimeout(()=>{o.style.opacity="0"},1e3)):s(e.message||"Upload failed")}catch(e){s("Error processing server response")}else s("Upload failed");l.disabled=!1}),n.addEventListener("error",()=>{s("Network error occurred"),l.disabled=!1}),n.addEventListener("abort",()=>{s("Upload cancelled"),l.disabled=!1}),n.open("POST",window.location.href,!0),n.send(t)}function p(t){const n=document.getElementById("videoPlayer"),o=n.querySelector("video"),a=n.querySelector(".share-input");o.src=`?stream=1&id=${t}`;const r=`${window.location.origin}${window.location.pathname}?id=${t}`;a.value=r,e.style.display="none",n.style.display="block",document.body.classList.add("video-mode");const l=n.querySelector(".copy-btn");l.addEventListener("click",()=>{a.select(),document.execCommand("copy"),l.textContent="Copied!",setTimeout(()=>{l.textContent="Copy Link"},2e3)})}l.addEventListener("click",()=>t.click()),t.addEventListener("change",i),["dragenter","dragover","dragleave","drop"].forEach(e=>{n.addEventListener(e,d)}),["dragenter","dragover"].forEach(e=>{n.addEventListener(e,()=>{n.style.borderColor="#000",n.style.background="#f8f8f8"})}),["dragleave","drop"].forEach(e=>{n.addEventListener(e,()=>{n.style.borderColor="#ddd",n.style.background="white"})}),n.addEventListener("drop",e=>{const n=e.dataTransfer.files;n.length>0&&(t.files=n,i())});const u=new URLSearchParams(window.location.search).get("id");u&&(p(u),document.body.classList.add("video-mode"))});
        
        // Auto-play video when URL contains video ID
        <?php if (isset($_GET['id'])): ?>
        document.addEventListener('DOMContentLoaded',()=>{
            const video = document.querySelector("video");
            if(video) {
                video.play().catch(e=>{
                    console.log("Video autoplay prevented:",e)
                });
                document.body.classList.add("video-mode");
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
