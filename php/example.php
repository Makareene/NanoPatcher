<?php

return function (object $pdo): void {
  $pdo->exec("
    INSERT INTO nanopatcher_example (
      name
    )
    VALUES (
      'Created by PHP patch'
    )
  ");
};

?>