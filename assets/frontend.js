(function(){
    'use strict';

    function hideNativeTrackingNotes(){
        var panels=document.querySelectorAll('.rar-wow-order-experience[data-rar-context="tracking"]');

        panels.forEach(function(panel){
            var scope=panel.closest('.woocommerce') || document;
            var lists=scope.querySelectorAll('ol.commentlist.notes');

            lists.forEach(function(list){
                if(panel.contains(list)){
                    return;
                }

                var heading=list.previousElementSibling;

                if(
                    heading &&
                    /^order updates$/i.test((heading.textContent || '').trim())
                ){
                    heading.hidden=true;
                }

                list.hidden=true;
            });
        });
    }

    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',hideNativeTrackingNotes);
    }else{
        hideNativeTrackingNotes();
    }
})();
