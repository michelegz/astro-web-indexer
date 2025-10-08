<!-- Smart Frame Finder Modal -->
<div id="sffModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-75 flex items-start md:items-center justify-center z-40 p-4 overflow-y-auto">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-7xl md:h-[90vh] flex flex-col transform transition-all my-auto">
        
        <!-- Header -->
        <div class="flex-shrink-0 flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 id="sffModalTitle" class="text-xl font-semibold text-white"><?php echo __('sff_modal_title'); ?></h3>
            <button id="sffCloseModalBtn" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>

        <!-- Main Content area using CSS Grid -->
        <div class="flex-1 grid grid-rows-[auto_auto_1fr] md:grid-rows-[1fr_auto] md:grid-cols-[1fr_2fr] mt-4 gap-6 md:overflow-hidden">

            <!-- Filters Panel: 1st row on mobile, 1st col on desktop -->
            <div id="sffFiltersPanel" class="md:row-span-1 md:col-span-1 md:overflow-y-auto md:pr-4 md:border-r md:border-gray-700">
                <!-- Filters will be injected here by JavaScript -->
            </div>

            <!-- Footer: 2nd row on mobile, 2nd row on desktop (spanning both columns) -->
            <div class="md:row-start-2 md:col-span-2 flex flex-col md:flex-row justify-between items-center pt-4 border-t border-gray-700 gap-4">
                <button id="sffFindBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded w-full md:w-auto"><?php echo __('sff_find_frames_btn'); ?></button>
                
                <div class="flex items-center justify-end gap-4 w-full md:w-auto">
                    <span id="sffResultCount" class="text-sm text-gray-400"></span>
                    <span id="sffTotalExposure" class="text-sm text-gray-400"></span>
                    <button id="sffDownloadBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled><?php echo __('download_selected'); ?></button>
                </div>
            </div>

            <!-- Results Panel: 3rd row on mobile, 1st row/2nd col on desktop -->
            <div id="sffResultsPanel" class="md:row-start-1 md:col-start-2 md:col-span-1 flex flex-col md:overflow-y-auto md:overflow-x-auto min-w-0">
                <!-- Results table will be injected here -->
            </div>
            
        </div>

    </div>
</div>
