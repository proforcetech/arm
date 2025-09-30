<?php
// file: templates/admin/estimate-edit.php
// Why: simple, safe row template; {{i}} is replaced by JS.
?>
<tr>
  <td>
    <select class="arm-part-search" name="items[{{i}}][part_id]" style="width:250px"></select>
  </td>
  <td><input type="text" name="items[{{i}}][description]" class="widefat"></td>
  <td><input type="number" step="0.01" name="items[{{i}}][qty]" value="1" min="0"></td>
  <td><input type="number" step="0.01" name="items[{{i}}][unit_price]" value="0.00" min="0"></td>
</tr>
