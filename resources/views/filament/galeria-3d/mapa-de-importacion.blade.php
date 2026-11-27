@php $vendor = asset('3d/vendor/three-r186'); @endphp
<script type="importmap">
{
    "imports": {
        "three": "{{ $vendor }}/three.module.js",
        "three/addons/": "{{ $vendor }}/addons/"
    }
}
</script>
