<?php

/*
|--------------------------------------------------------------------------
| Mensajes de validación en español
|--------------------------------------------------------------------------
|
| No existían. La aplicación corre con `app.locale = es` y `fallback_locale = es`,
| así que cuando una regla fallaba no había ni traducción ni respaldo en inglés: el
| usuario veía literalmente «validation.max.string» y no tenía manera de saber qué
| campo estaba mal ni por qué.
|
| Están todas las reglas de Laravel, no solo las que el sistema usa hoy: cuesta lo
| mismo y evita que la próxima regla que alguien escriba vuelva a mostrar una clave
| cruda en la cara de un cliente.
|
| Español colombiano neutro, sin voseo, igual que el resto del producto.
|
*/

return [
    'accepted'             => 'Debes aceptar :attribute.',
    'accepted_if'          => 'Debes aceptar :attribute cuando :other es :value.',
    'active_url'           => ':Attribute no es una URL válida.',
    'after'                => ':Attribute debe ser una fecha posterior a :date.',
    'after_or_equal'       => ':Attribute debe ser una fecha igual o posterior a :date.',
    'alpha'                => ':Attribute solo puede tener letras.',
    'alpha_dash'           => ':Attribute solo puede tener letras, números, guiones y guiones bajos.',
    'alpha_num'            => ':Attribute solo puede tener letras y números.',
    'any_of'               => ':Attribute no es válido.',
    'array'                => ':Attribute debe ser una lista.',
    'ascii'                => ':Attribute solo puede tener caracteres y símbolos de un byte.',
    'before'               => ':Attribute debe ser una fecha anterior a :date.',
    'before_or_equal'      => ':Attribute debe ser una fecha igual o anterior a :date.',

    'between' => [
        'array'   => ':Attribute debe tener entre :min y :max elementos.',
        'file'    => ':Attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':Attribute debe estar entre :min y :max.',
        'string'  => ':Attribute debe tener entre :min y :max caracteres.',
    ],

    'boolean'              => ':Attribute solo puede ser verdadero o falso.',
    'can'                  => ':Attribute tiene un valor no permitido.',
    'confirmed'            => ':Attribute no coincide con su confirmación.',
    'contains'             => 'A :attribute le falta un valor obligatorio.',
    'current_password'     => 'La contraseña no es correcta.',
    'date'                 => ':Attribute no es una fecha válida.',
    'date_equals'          => ':Attribute debe ser una fecha igual a :date.',
    'date_format'          => ':Attribute no corresponde al formato :format.',
    'decimal'              => ':Attribute debe tener :decimal decimales.',
    'declined'             => 'Debes rechazar :attribute.',
    'declined_if'          => 'Debes rechazar :attribute cuando :other es :value.',
    'different'            => ':Attribute y :other deben ser distintos.',
    'digits'               => ':Attribute debe tener :digits dígitos.',
    'digits_between'       => ':Attribute debe tener entre :min y :max dígitos.',
    'dimensions'           => ':Attribute tiene dimensiones de imagen no válidas.',
    'distinct'             => ':Attribute tiene un valor repetido.',
    'doesnt_contain'       => ':Attribute no puede contener ninguno de estos valores: :values.',
    'doesnt_end_with'      => ':Attribute no puede terminar con: :values.',
    'doesnt_start_with'    => ':Attribute no puede empezar con: :values.',
    'email'                => ':Attribute no es un correo válido.',
    'ends_with'            => ':Attribute debe terminar con: :values.',
    'enum'                 => 'El valor de :attribute no está entre los permitidos.',
    'exists'               => 'El valor de :attribute no existe.',
    'extensions'           => ':Attribute debe tener una de estas extensiones: :values.',
    'file'                 => ':Attribute debe ser un archivo.',
    'filled'              => ':Attribute no puede quedar vacío.',

    'gt' => [
        'array'   => ':Attribute debe tener más de :value elementos.',
        'file'    => ':Attribute debe pesar más de :value kilobytes.',
        'numeric' => ':Attribute debe ser mayor que :value.',
        'string'  => ':Attribute debe tener más de :value caracteres.',
    ],

    'gte' => [
        'array'   => ':Attribute debe tener :value elementos o más.',
        'file'    => ':Attribute debe pesar :value kilobytes o más.',
        'numeric' => ':Attribute debe ser mayor o igual que :value.',
        'string'  => ':Attribute debe tener :value caracteres o más.',
    ],

    'hex_color'            => ':Attribute debe ser un color hexadecimal válido.',
    'image'                => ':Attribute debe ser una imagen.',
    'in'                   => 'El valor de :attribute no está entre los permitidos.',
    'in_array'             => ':Attribute no existe en :other.',
    'integer'              => ':Attribute debe ser un número entero.',
    'ip'                   => ':Attribute debe ser una dirección IP válida.',
    'ipv4'                 => ':Attribute debe ser una dirección IPv4 válida.',
    'ipv6'                 => ':Attribute debe ser una dirección IPv6 válida.',
    'json'                 => ':Attribute debe ser un JSON válido.',
    'list'                 => ':Attribute debe ser una lista.',
    'lowercase'            => ':Attribute debe estar en minúsculas.',

    'lt' => [
        'array'   => ':Attribute debe tener menos de :value elementos.',
        'file'    => ':Attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':Attribute debe ser menor que :value.',
        'string'  => ':Attribute debe tener menos de :value caracteres.',
    ],

    'lte' => [
        'array'   => ':Attribute no puede tener más de :value elementos.',
        'file'    => ':Attribute debe pesar :value kilobytes o menos.',
        'numeric' => ':Attribute debe ser menor o igual que :value.',
        'string'  => ':Attribute debe tener :value caracteres o menos.',
    ],

    'mac_address'          => ':Attribute debe ser una dirección MAC válida.',

    'max' => [
        'array'   => ':Attribute no puede tener más de :max elementos.',
        'file'    => ':Attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'string'  => ':Attribute no puede tener más de :max caracteres.',
    ],

    'max_digits'           => ':Attribute no puede tener más de :max dígitos.',
    'mimes'                => ':Attribute debe ser un archivo de tipo: :values.',
    'mimetypes'            => ':Attribute debe ser un archivo de tipo: :values.',

    'min' => [
        'array'   => ':Attribute debe tener al menos :min elementos.',
        'file'    => ':Attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':Attribute debe ser al menos :min.',
        'string'  => ':Attribute debe tener al menos :min caracteres.',
    ],

    'min_digits'           => ':Attribute debe tener al menos :min dígitos.',
    'missing'              => ':Attribute no debe venir.',
    'missing_if'           => ':Attribute no debe venir cuando :other es :value.',
    'missing_unless'       => ':Attribute no debe venir salvo que :other sea :value.',
    'missing_with'         => ':Attribute no debe venir si viene :values.',
    'missing_with_all'     => ':Attribute no debe venir si vienen :values.',
    'multiple_of'          => ':Attribute debe ser múltiplo de :value.',
    'not_in'               => 'El valor de :attribute no está permitido.',
    'not_regex'            => 'El formato de :attribute no es válido.',
    'numeric'              => ':Attribute debe ser un número.',

    'password' => [
        'letters'       => 'La contraseña debe tener al menos una letra.',
        'mixed'         => 'La contraseña debe tener al menos una mayúscula y una minúscula.',
        'numbers'       => 'La contraseña debe tener al menos un número.',
        'symbols'       => 'La contraseña debe tener al menos un símbolo.',
        'uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra.',
    ],

    'present'              => ':Attribute debe venir.',
    'present_if'           => ':Attribute debe venir cuando :other es :value.',
    'present_unless'       => ':Attribute debe venir salvo que :other sea :value.',
    'present_with'         => ':Attribute debe venir si viene :values.',
    'present_with_all'     => ':Attribute debe venir si vienen :values.',
    'prohibited'           => ':Attribute no está permitido.',
    'prohibited_if'        => ':Attribute no está permitido cuando :other es :value.',
    'prohibited_if_accepted' => ':Attribute no está permitido cuando se acepta :other.',
    'prohibited_if_declined' => ':Attribute no está permitido cuando se rechaza :other.',
    'prohibited_unless'    => ':Attribute no está permitido salvo que :other esté entre :values.',
    'prohibits'            => ':Attribute no permite que venga :other.',
    'regex'                => 'El formato de :attribute no es válido.',
    'required'             => ':Attribute es obligatorio.',
    'required_array_keys'  => ':Attribute debe incluir: :values.',
    'required_if'          => ':Attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => ':Attribute es obligatorio cuando se acepta :other.',
    'required_if_declined' => ':Attribute es obligatorio cuando se rechaza :other.',
    'required_unless'      => ':Attribute es obligatorio salvo que :other esté entre :values.',
    'required_with'        => ':Attribute es obligatorio cuando viene :values.',
    'required_with_all'    => ':Attribute es obligatorio cuando vienen :values.',
    'required_without'     => ':Attribute es obligatorio cuando no viene :values.',
    'required_without_all' => ':Attribute es obligatorio cuando no viene ninguno de :values.',
    'same'                 => ':Attribute y :other deben coincidir.',

    'size' => [
        'array'   => ':Attribute debe tener :size elementos.',
        'file'    => ':Attribute debe pesar :size kilobytes.',
        'numeric' => ':Attribute debe ser :size.',
        'string'  => ':Attribute debe tener :size caracteres.',
    ],

    'starts_with'          => ':Attribute debe empezar con: :values.',
    'string'               => ':Attribute debe ser texto.',
    'timezone'             => ':Attribute debe ser una zona horaria válida.',
    'unique'               => ':Attribute ya está en uso.',
    'uploaded'             => 'No se pudo subir :attribute.',
    'uppercase'            => ':Attribute debe estar en mayúsculas.',
    'url'                  => ':Attribute no es una URL válida.',
    'ulid'                 => ':Attribute debe ser un ULID válido.',
    'uuid'                 => ':Attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Nombres de los campos
    |--------------------------------------------------------------------------
    |
    | Sin esto, el mensaje dice «descripcion_corta no puede tener más de 1000
    | caracteres»: el nombre de la columna, que no es como se llama el campo en la
    | pantalla. Están los que el usuario ve, no todas las columnas del sistema.
    |
    */

    'attributes' => [
        'nombre'                => 'el nombre',
        'referencia'            => 'la referencia',
        'descripcion_corta'     => 'la descripción corta',
        'descripcion_larga'     => 'la descripción larga',
        'unidad_medida'         => 'la unidad de medida',
        'categoria_id'          => 'la categoría',
        'proveedor_id'          => 'el proveedor',
        'precio_costo'          => 'el precio de costo',
        'stock_minimo'          => 'el stock mínimo',
        'stock_maximo'          => 'el stock máximo',
        'email'                 => 'el correo',
        'password'              => 'la contraseña',
        'telefono'              => 'el teléfono',
        'direccion'             => 'la dirección',
        'documento'             => 'el documento',
        'cliente_id'            => 'el cliente',
        'sede_id'               => 'la sede',
        'bodega_id'             => 'la bodega',
        'cantidad'              => 'la cantidad',
        'fecha'                 => 'la fecha',
        'fecha_entrega'         => 'la fecha de entrega',
        'observaciones'         => 'las observaciones',
        'notas'                 => 'las notas',
        'etiqueta'              => 'la etiqueta',
        'clave'                 => 'la clave',
        'valor'                 => 'el valor',
        'margen_pct'            => 'el margen',
        'precio'                => 'el precio',
        'imagenes'              => 'las imágenes',
        'archivo'               => 'el archivo',
        'aporte_descripcion'    => 'qué es y para qué sirve',
        'aporte_caracteristicas' => 'las características técnicas',
        'aporte_ventajas'       => 'las ventajas',
        'aporte_beneficios'     => 'los beneficios',
        'aporte_componentes'    => 'los componentes',
    ],
];
