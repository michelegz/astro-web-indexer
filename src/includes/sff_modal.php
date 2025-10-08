<!-- Smart Frame Finder Modal -->
<div id="sffModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center z-40">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-7xl h-[90vh] flex flex-col transform transition-all">
        
        <!-- Header -->
        <div class="flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 id="sffModalTitle" class="text-xl font-semibold text-white"><?php echo __('sff_modal_title'); ?></h3>
            <button id="sffCloseModalBtn" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex mt-4 overflow-hidden gap-6">

            <!-- Filters Panel (Left) -->
            <div id="sffFiltersPanel" class="w-1/3 overflow-y-auto pr-4 border-r border-gray-700">
                <p class="text-gray-400 text-sm"><?php echo __('sff_configure_search'); ?></p>
                <!-- Filters will be injected here by JavaScript -->
            </div>

            <!-- Results Panel (Right) -->
            <div id="sffResultsPanel" class="w-2/3 flex flex-col">
                <p class="text-gray-400 text-sm"><?php echo __('sff_results_placeholder'); ?></p>
                <!-- Results table will be injected here -->
            </div>
            
        </div>

        <!-- Footer -->
        <div class="flex justify-between items-center pt-4 border-t border-gray-700 mt-4">
            <div>
                <button id="sffFindBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded"><?php echo __('sff_find_frames_btn'); ?></button>
            </div>
            <div>
                <span id="sffResultCount" class="text-sm text-gray-400 mr-4"></span>
                <span id="sffTotalExposure" class="text-sm text-gray-400 mr-4"></span>
                <button id="sffDownloadBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled><?php echo __('download_selected'); ?></button>
            </div>
        </div>

    </div>
</div>
