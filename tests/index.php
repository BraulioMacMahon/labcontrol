<?php
/**
 * LabControl - Test Management Interface
 * Dashboard central para execução de scripts de teste, debug e utilitários.
 */

$testDirs = [
    'Root Tests' => 'root',
    'Backend API Tests' => 'backend',
    'Frontend Debug' => 'frontend'
];

function getFiles($dir) {
    if (!is_dir(__DIR__ . '/' . $dir)) return [];
    $files = scandir(__DIR__ . '/' . $dir);
    return array_filter($files, function($f) {
        return !in_array($f, ['.', '..']) && (strpos($f, '.php') !== false || strpos($f, '.html') !== false);
    });
}
?>
<!DOCTYPE html>
<html lang="pt-br" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LabControl | Test Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #050505;
            color: #e2e8f0;
        }
        .glass-panel {
            background: rgba(15, 15, 15, 0.75);
            backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }
        .glass-card:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(59, 130, 246, 0.3);
            transform: translateY(-2px);
        }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 20px; }
    </style>
</head>
<body class="p-8 md:p-12 min-h-screen flex flex-col items-center">
    
    <div class="w-full max-w-6xl space-y-12">
        <!-- Header -->
        <header class="flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
                <h1 class="text-4xl font-black text-white tracking-tight flex items-center gap-3">
                    <span class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-xl">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    </span>
                    Test Management
                </h1>
                <p class="text-blue-500 font-bold uppercase tracking-[0.2em] text-[10px] mt-2 ml-1">Infrastructure Debugging Suite</p>
            </div>
            <a href="../labcontrol-frontend/" class="px-6 py-3 bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl text-sm font-bold transition-all flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Voltar ao App
            </a>
        </header>

        <!-- Main Content -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <?php foreach ($testDirs as $title => $dir): ?>
                <section class="glass-panel p-6 rounded-[2.5rem] flex flex-col min-h-[400px]">
                    <div class="flex items-center justify-between mb-6 px-2">
                        <h2 class="text-xl font-bold text-white"><?php echo $title; ?></h2>
                        <span class="px-3 py-1 bg-white/5 rounded-full text-[10px] font-mono text-blue-400">
                            /<?php echo $dir; ?>
                        </span>
                    </div>
                    
                    <div class="flex-1 space-y-3 overflow-y-auto pr-2 custom-scrollbar">
                        <?php 
                        $files = getFiles($dir);
                        if (empty($files)): ?>
                            <p class="text-gray-600 text-xs text-center py-10 italic">Nenhum script encontrado.</p>
                        <?php else: 
                            foreach ($files as $file): 
                                $isFix = strpos($file, 'fix-') !== false;
                                $isDebug = strpos($file, 'debug-') !== false;
                                $isTest = strpos($file, 'test-') !== false;
                                
                                $iconColor = "text-blue-500";
                                if ($isFix) $iconColor = "text-emerald-500";
                                if ($isDebug) $iconColor = "text-amber-500";
                                ?>
                                <a href="<?php echo $dir . '/' . $file; ?>" target="_blank" class="glass-card p-4 rounded-2xl flex items-center gap-4 group">
                                    <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center transition-colors group-hover:bg-white/10">
                                        <?php if ($isFix): ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-500"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                        <?php elseif ($isDebug): ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-500"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M7 7l-2-2"/><path d="M17 7l2-2"/><path d="M7 17l-2 2"/><path d="M17 17l2 2"/><rect width="10" height="14" x="7" y="7" rx="2"/></svg>
                                        <?php else: ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-500"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-bold text-gray-200 truncate group-hover:text-white transition-colors">
                                            <?php echo str_replace(['test-', 'debug-', 'fix-', '.php', '.html'], '', $file); ?>
                                        </p>
                                        <p class="text-[9px] font-mono text-gray-600 truncate"><?php echo $file; ?></p>
                                    </div>
                                    <div class="opacity-0 group-hover:opacity-100 transition-opacity">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-500"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    </div>
                                </a>
                            <?php endforeach; 
                        endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <!-- Footer Info -->
        <footer class="text-center pb-12">
            <p class="text-[10px] font-mono text-gray-700 uppercase tracking-widest">
                LabControl Security & Diagnostics • 2026 Build • Admin Access Restricted
            </p>
        </footer>
    </div>

</body>
</html>
