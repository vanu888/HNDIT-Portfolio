</main> <!-- End Main Content Wrapper -->

    <!-- WEBSITE FOOTER -->
    <footer class="bg-white border-t border-slate-200 mt-auto">
        <div class="max-w-7xl mx-auto px-6 py-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                
                <!-- Brand Info -->
                <div class="text-center md:text-left">
                    <h4 class="brand-font text-lg font-bold text-slate-900">HNDIT PORTFOLIO</h4>
                    <p class="text-xs text-slate-500 uppercase tracking-widest mt-1">Official Academic Registry</p>
                </div>

                <!-- Copyright -->
                <div class="text-slate-400 text-sm font-medium">
                    &copy; <?php echo date('Y'); ?> HNDIT Department. All Rights Reserved.
                </div>
            </div>
        </div>
    </footer>

    <!-- GLOBAL JAVASCRIPT -->
    <script>
        // --- LIGHTBOX / MEDIA GALLERY LOGIC ---
        let currentGallery = [];
        let currentIndex = 0;

        /**
         * Open Media in Lightbox
         * @param {string} url - File path or Video URL
         * @param {string} type - 'image', 'video', or 'pdf'
         */
        function openMedia(url, type) {
            if(!url) return;
            
            const modal = document.getElementById('mediaModal'); 
            const container = document.getElementById('mediaContainer');
            const counter = document.getElementById('mediaCounter');
            
            // Reset Gallery State
            currentGallery = []; 
            if(counter) counter.innerText = '';

            // Show Modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Render Content
            if(type === 'image') {
                container.innerHTML = `<img src="${url}" class="max-h-[90vh] max-w-[90vw] shadow-2xl object-contain animate-fade-in border-4 border-white rounded-sm">`;
            } else if (type === 'pdf') {
                container.innerHTML = `<iframe src="${url}" class="w-[85vw] h-[85vh] shadow-2xl bg-white animate-fade-in rounded-sm"></iframe>`;
            } else {
                // Handle Youtube/Video
                let src = url; 
                if(src.includes('watch?v=')) src = src.replace('watch?v=', 'embed/'); 
                else if(src.includes('youtu.be/')) src = src.replace('youtu.be/', 'www.youtube.com/embed/');
                
                container.innerHTML = `<iframe src="${src}" class="w-[85vw] h-[80vh] shadow-2xl bg-black animate-fade-in rounded-sm" frameborder="0" allowfullscreen></iframe>`;
            }
        }

        /**
         * Open Gallery Mode (Multiple Images)
         * Used in Portfolio View
         */
        function openGallery(postId, index) {
            if(!window.postMediaData || !window.postMediaData[postId]) return;
            
            currentGallery = window.postMediaData[postId];
            currentIndex = index;
            
            document.getElementById('mediaModal').classList.remove('hidden');
            document.getElementById('mediaModal').classList.add('flex');
            updateLightbox();
        }

        function updateLightbox() {
            const container = document.getElementById('mediaContainer');
            const item = currentGallery[currentIndex];
            const counter = document.getElementById('mediaCounter');
            
            if(counter) counter.innerText = `${currentIndex + 1} / ${currentGallery.length}`;
            
            // Re-use rendering logic logic (simplified here)
            openMedia(item.file_path, item.media_type);
        }

        function changeMedia(direction) {
            if(currentGallery.length === 0) return;
            
            let newIndex = currentIndex + direction;
            if(newIndex >= 0 && newIndex < currentGallery.length) {
                currentIndex = newIndex;
                updateLightbox();
            }
        }

        function closeMedia() { 
            const modal = document.getElementById('mediaModal');
            modal.classList.add('hidden'); 
            modal.classList.remove('flex');
            document.getElementById('mediaContainer').innerHTML = ''; // Stop video playback
        }
        
        // Keyboard Shortcuts
        document.addEventListener('keydown', function(event) { 
            if (event.key === "Escape") closeMedia(); 
            if (event.key === "ArrowRight") changeMedia(1);
            if (event.key === "ArrowLeft") changeMedia(-1);
        });
    </script>
</body>
</html>
