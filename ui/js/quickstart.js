document.querySelectorAll('[data-model-select]').forEach(function(select){
    // Show the chosen saved model, never a hardcoded price or a replacement route.
    function updateRecap(){
        select.closest('.qs-connector-card').querySelector('[data-model-recap]').textContent=select.selectedOptions[0]?.dataset.model||'';
    }
    select.addEventListener('change',updateRecap);
    updateRecap();
});
