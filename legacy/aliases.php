<?php
/**
 * The global names of the classes that moved to a namespace: old name => new name. A name here is an alias of the class it
 * stands for (same class, same static state). A class whose methods changed is a thin subclass in this folder, in a file named
 * after it (OpenSim_Config.php).
 *
 * Everything in here is deprecated: the helpers and w4os move to the new names when they are touched, then the old names go.
 */

return [
    'OpenSim_Oar' => \OpenSim\Archives\Oar::class,
];
