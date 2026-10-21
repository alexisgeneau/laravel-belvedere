<script>
    export default {
        emits: ['load'],
    };
</script>

<template>
    <tr class="new-entries">
        <td colspan="100" class="text-center py-1">
            <small><a href="#" v-on:click.prevent="$emit('load')">Load New Entries</a></small>
        </td>
    </tr>
</template>
