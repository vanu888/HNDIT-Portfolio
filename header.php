<?php
require_once 'db_connect.php';
require_once 'functions.php';

// Breadcrumbs Logic
$page = $_GET['page'] ?? 'home';
$tab = $_GET['tab'] ?? '';
$breadcrumbs = [['name' => 'Home', 'link' => 'index.php?page=home']];

if ($page === 'library') $breadcrumbs[] = ['name' => 'Library', 'link' => 'index.php?page=library'];
if ($page === 'about') $breadcrumbs[] = ['name' => 'About Us', 'link' => 'index.php?page=about'];
if ($page === 'login') $breadcrumbs[] = ['name' => 'Login', 'link' => ''];
if ($page === 'dashboard') {
    $breadcrumbs[] = ['name' => 'Dashboard', 'link' => 'index.php?page=dashboard'];
    if ($tab) $breadcrumbs[] = ['name' => ucfirst($tab), 'link' => ''];
}
if ($page === 'portfolio') $breadcrumbs[] = ['name' => 'Portfolio', 'link' => ''];
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNDIT Portfolio Registry</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: { uni: { blue: '#1e3a8a', gold: '#b45309', dark: '#0f172a' } } } }
        }
    </script>
    
    <!-- Icons & Fonts -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Lato', sans-serif; transition: background-color 0.3s, color 0.3s; }
        h1, h2, h3, h4, .brand-font { font-family: 'Playfair Display', serif; }
        
        /* Dark Mode Transitions */
        .dark body { background-color: #0f172a; color: #e2e8f0; }
        .dark .bg-white { background-color: #1e293b; }
        .dark .text-slate-900 { color: #f1f5f9; }
        .dark .text-slate-800 { color: #e2e8f0; }
        .dark .text-slate-700 { color: #cbd5e1; }
        .dark .text-slate-600 { color: #94a3b8; }
        .dark .text-slate-500 { color: #64748b; }
        .dark .bg-slate-50 { background-color: #0f172a; }
        .dark .bg-slate-100 { background-color: #334155; }
        .dark .border-slate-200 { border-color: #334155; }
        .dark .border-slate-100 { border-color: #1e293b; }
        .dark input, .dark select, .dark textarea { background-color: #0f172a; border-color: #475569; color: white; }
        
        /* Components */
        .btn-primary { background-color: #1e3a8a; color: white; transition: all 0.2s; border-radius: 4px; }
        .btn-primary:hover { background-color: #172554; transform: translateY(-1px); }
        .card { background: white; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 4px; }
        .dark .card { background-color: #1e293b; border-color: #334155; }
        
        /* Utility */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 4px; }
        .dark ::-webkit-scrollbar-track { background: #0f172a; }
        .dark ::-webkit-scrollbar-thumb { background: #475569; }
        
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .pdf-preview { background-color: #fff1f2; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; border: 1px solid #fecdd3; color: #e11d48; }
        .hidden-row { display: none !important; }
        
        /* Modal Backdrop */
        .modal-backdrop { background-color: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }

        /* Media Grids */
        .media-grid-1 { grid-template-columns: 1fr; }
        .media-grid-2 { grid-template-columns: 1fr 1fr; }
        .media-grid-3 { grid-template-columns: 1fr 1fr; grid-template-rows: 200px 200px; }
        .media-grid-3 :first-child { grid-column: span 2; }
    </style>
    
    <!-- Initialize Global Objects -->
    <script>window.postMediaData = {};</script>
</head>
<body class="flex flex-col min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-900 dark:text-slate-200">

    <!-- FLASH MESSAGES -->
    <?php 
    $flash = getFlash();
    // Fallback for URL params
    if (!$flash && (isset($_GET['msg']) || isset($_GET['error']))) {
        $flash = [
            'type' => isset($_GET['error']) ? 'error' : 'success',
            'message' => $_GET['msg'] ?? $_GET['error']
        ];
    }
    if ($flash): ?>
        <div id="toast" class="fixed top-24 right-5 z-[100] animate-fade-in px-6 py-4 bg-white dark:bg-slate-800 border-l-4 shadow-xl flex items-center gap-4 <?php echo $flash['type'] === 'error' ? 'border-red-600 text-red-600' : 'border-green-600 text-green-600'; ?>">
            <i class="<?php echo $flash['type'] === 'error' ? 'fas fa-exclamation-triangle' : 'fas fa-check-circle'; ?> text-2xl"></i>
            <div>
                <h4 class="font-bold text-sm uppercase tracking-wide"><?php echo $flash['type'] === 'error' ? 'Error' : 'Success'; ?></h4>
                <p class="text-sm text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($flash['message']); ?></p>
            </div>
            <button onclick="this.parentElement.remove()" class="ml-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <script>setTimeout(() => document.getElementById('toast')?.remove(), 5000);</script>
    <?php endif; ?>

    <!-- DELETE CONFIRMATION MODAL -->
    <div id="deleteModal" class="fixed inset-0 z-[150] hidden items-center justify-center modal-backdrop">
        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-md p-6 transform transition-all scale-95 animate-fade-in border-t-4 border-red-600">
            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2"><i class="fas fa-exclamation-circle text-red-600 mr-2"></i>Confirm Action</h3>
            <p class="text-slate-600 dark:text-slate-300 mb-6">Are you sure you want to delete this item? This action cannot be undone.</p>
            <div class="flex justify-end gap-3">
                <button onclick="closeDeleteModal()" class="px-4 py-2 rounded bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-200">Cancel</button>
                <a id="confirmDeleteBtn" href="#" class="px-4 py-2 rounded bg-red-600 text-white font-bold hover:bg-red-700">Yes, Delete</a>
            </div>
        </div>
    </div>

    <!-- GLOBAL LIGHTBOX MODAL -->
    <div id="mediaModal" class="fixed inset-0 bg-black/95 hidden z-[150] flex flex-col items-center justify-center backdrop-blur-sm p-4" onclick="closeMedia()">
        <button class="absolute top-6 right-6 text-white text-4xl hover:text-gray-300 z-[151]">&times;</button>
        
        <div class="flex-1 w-full max-w-7xl flex items-center justify-center relative">
            <button onclick="changeMedia(-1); event.stopPropagation();" class="absolute left-4 z-[152] text-white/50 hover:text-white text-6xl p-2 transition">&lsaquo;</button>
            <div id="mediaContainer" class="w-full h-full flex items-center justify-center" onclick="event.stopPropagation()"></div>
            <button onclick="changeMedia(1); event.stopPropagation();" class="absolute right-4 z-[152] text-white/50 hover:text-white text-6xl p-2 transition">&rsaquo;</button>
        </div>
        
        <div class="text-white/70 mt-4 font-mono text-sm" id="mediaCounter"></div>
    </div>

    <!-- HEADER -->
    <header class="bg-uni-blue dark:bg-slate-950 text-white shadow-md sticky top-0 z-50 transition-colors duration-300">
        <div class="max-w-7xl mx-auto px-6 h-20 flex justify-between items-center">
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="bg-white/10 p-2 rounded border border-white/20 group-hover:bg-white/20 transition"><i class="fas fa-university text-2xl"></i></div>
                <div class="leading-tight">
                    <div class="brand-font text-xl font-bold tracking-wide">HNDIT</div>
                    <div class="text-[10px] uppercase tracking-widest text-blue-200">Portfolio Registry</div>
                </div>
            </a>
            
            <div class="flex items-center gap-6">
                <button onclick="toggleDarkMode()" class="p-2 rounded-full hover:bg-white/10 transition text-blue-200 hover:text-white"><i id="themeIcon" class="fas fa-moon text-lg"></i></button>
                
                <nav class="hidden md:flex items-center gap-8 text-sm font-medium tracking-wide">
                    <a href="index.php?page=home" class="hover:text-blue-200 uppercase transition">Home</a>
                    <a href="index.php?page=library" class="hover:text-blue-200 uppercase transition">Library</a>
                    <a href="index.php?page=about" class="hover:text-blue-200 uppercase transition">About</a>
                    
                    <?php if(isLoggedIn()): ?>
                        <a href="index.php?page=dashboard" class="bg-white text-uni-blue px-5 py-2 rounded-sm font-bold hover:bg-gray-100 shadow-sm flex items-center gap-2">
                            <i class="fas fa-user-circle"></i> <?php echo isHod() ? 'ADMIN' : 'REP'; ?>
                        </a>
                    <?php else: ?>
                        <a href="index.php?page=login" class="border border-white/30 px-5 py-2 rounded-sm hover:bg-white/10 transition uppercase">Staff Login</a>
                    <?php endif; ?>
                </nav>
                <button class="md:hidden text-2xl" onclick="toggleMobileMenu()"><i class="fas fa-bars"></i></button>
            </div>
        </div>
    </header>

    <!-- BREADCRUMBS -->
    <div class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 py-3 shadow-sm transition-colors duration-300">
        <div class="max-w-7xl mx-auto px-6 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            <?php foreach($breadcrumbs as $k => $b) {
                if ($k > 0) echo ' <span class="mx-2 text-slate-300">/</span> ';
                if ($b['link']) echo '<a href="'.$b['link'].'" class="hover:text-uni-blue dark:hover:text-blue-400 transition">'.$b['name'].'</a>';
                else echo '<span class="text-uni-blue dark:text-blue-400">'.$b['name'].'</span>';
            } ?>
        </div>
    </div>

    <main class="flex-grow max-w-7xl w-full mx-auto p-6 md:p-10">
    
    <!-- LOGOUT FORM (Hidden) -->
    <form id="logoutForm" action="index.php" method="POST" class="hidden">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    </form>

    <script>
        // Dark Mode Logic
        function toggleDarkMode() {
            const html = document.documentElement;
            const icon = document.getElementById('themeIcon');
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                localStorage.setItem('theme', 'light');
                icon.classList.replace('fa-sun', 'fa-moon');
            } else {
                html.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                icon.classList.replace('fa-moon', 'fa-sun');
            }
        }
        
        // Init Theme
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
            document.getElementById('themeIcon').classList.replace('fa-moon', 'fa-sun');
        }
    </script>
