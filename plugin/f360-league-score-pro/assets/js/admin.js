(function(){
  document.addEventListener('DOMContentLoaded', function(){
    var tbody = document.getElementById('f360ls-leagues-sortable');
    var input = document.getElementById('f360ls-league-order');
    if(!tbody || !input) return;
    var dragged = null;
    function updateOrder(){
      input.value = Array.prototype.map.call(tbody.querySelectorAll('tr[data-league-id]'), function(row){
        return row.getAttribute('data-league-id');
      }).join(',');
    }
    tbody.addEventListener('dragstart', function(e){
      var row = e.target.closest('tr[data-league-id]');
      if(!row) return;
      dragged = row;
      row.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    tbody.addEventListener('dragend', function(){
      if(dragged) dragged.classList.remove('is-dragging');
      dragged = null;
      updateOrder();
    });
    tbody.addEventListener('dragover', function(e){
      e.preventDefault();
      var row = e.target.closest('tr[data-league-id]');
      if(!row || !dragged || row === dragged) return;
      var rect = row.getBoundingClientRect();
      var after = (e.clientY - rect.top) > rect.height / 2;
      tbody.insertBefore(dragged, after ? row.nextSibling : row);
    });
    updateOrder();
  });
})();
