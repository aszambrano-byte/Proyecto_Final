<script setup lang="ts">
import { reactive, ref, computed, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
import FormField from "../../components/FormField.vue";
import {
    ayudaDocumento,
    documentoNumerico,
    longitudDocumento,
    normalizarDocumento,
    normalizarTelefono,
    tiposDocumentoColombia,
} from "../../utils/validation";

// Estado del formulario
const state = reactive({
    tipoDocumento: "CC",
    numeroDocumento: "",
    razonSocial: "",
    direccion: "",
    telefono: "",
    email: "",
});
watch(
    () => state.tipoDocumento,
    (tipo) => {
        state.numeroDocumento = normalizarDocumento(
            state.numeroDocumento,
            tipo,
        );
    },
);

// Obtener errores de validación del backend
const page = usePage();
const backendErrors = computed(() => page.props.errors || {});

// Convertir errores de array a string (Laravel retorna arrays)
const errors = computed(() => {
    const result: Record<string, string> = {};
    Object.keys(backendErrors.value).forEach((key) => {
        const error = backendErrors.value[key];
        result[key] = Array.isArray(error) ? error[0] : error;
    });
    return result;
});

// Loading state
const isLoading = ref(false);

// Submit handler
const handleSubmit = () => {
    isLoading.value = true;

    router.post(route("clientes.store"), state, {
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const handleCancel = () => {
    router.visit(route("clientes.index"));
};
</script>

<template>
    <UDashboardPanel>
        <template #header>
            <UDashboardNavbar title="Crear Cliente">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="p-4 sm:p-6">
                <div class="mb-6">
                    <h2 class="text-2xl font-semibold">Nuevo Cliente</h2>
                    <p class="text-sm text-muted mt-1">
                        Complete los datos del cliente
                    </p>
                </div>

                <form @submit.prevent="handleSubmit" class="space-y-6">
                    <!-- Fila 1: Tipo y Número de Documento -->
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <FormField
                            label="Tipo de Documento"
                            name="tipoDocumento"
                            required
                            :error="errors.tipoDocumento"
                        >
                            <template #default="{ fieldAttrs }">
                                <USelect
                                    v-bind="fieldAttrs"
                                    v-model="state.tipoDocumento"
                                    :items="tiposDocumentoColombia"
                                    placeholder="Seleccione tipo de documento"
                                    size="xl"
                                    class="w-full"
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="Número de Documento"
                            name="numeroDocumento"
                            required
                            :error="errors.numeroDocumento"
                            :hint="ayudaDocumento(state.tipoDocumento)"
                        >
                            <template #default="{ fieldAttrs }">
                                <UInput
                                    v-bind="fieldAttrs"
                                    v-model="state.numeroDocumento"
                                    placeholder="Ingrese el número de documento"
                                    icon="i-lucide-credit-card"
                                    :inputmode="
                                        documentoNumerico(state.tipoDocumento)
                                            ? 'numeric'
                                            : 'text'
                                    "
                                    :maxlength="
                                        longitudDocumento(state.tipoDocumento)
                                    "
                                    autocomplete="off"
                                    required
                                    size="xl"
                                    class="w-full"
                                    @update:model-value="
                                        state.numeroDocumento =
                                            normalizarDocumento(
                                                String($event),
                                                state.tipoDocumento,
                                            )
                                    "
                                />
                            </template>
                        </FormField>
                    </div>

                    <!-- Fila 2: Razón Social y Dirección -->
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <FormField
                            label="Razón Social"
                            name="razonSocial"
                            required
                            :error="errors.razonSocial"
                        >
                            <template #default="{ fieldAttrs }">
                                <UInput
                                    v-bind="fieldAttrs"
                                    v-model="state.razonSocial"
                                    placeholder="Ingrese la razón social"
                                    icon="i-lucide-building"
                                    size="xl"
                                    class="w-full"
                                    required
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="Dirección"
                            name="direccion"
                            required
                            :error="errors.direccion"
                        >
                            <template #default="{ fieldAttrs }">
                                <UInput
                                    v-bind="fieldAttrs"
                                    v-model="state.direccion"
                                    placeholder="Ingrese la dirección"
                                    icon="i-lucide-map-pin"
                                    size="xl"
                                    class="w-full"
                                    required
                                />
                            </template>
                        </FormField>
                    </div>

                    <!-- Fila 3: Teléfono y Email -->
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <FormField
                            label="Teléfono"
                            name="telefono"
                            required
                            :error="errors.telefono"
                        >
                            <template #default="{ fieldAttrs }">
                                <UInput
                                    v-bind="fieldAttrs"
                                    v-model="state.telefono"
                                    type="tel"
                                    inputmode="tel"
                                    autocomplete="tel"
                                    maxlength="13"
                                    placeholder="0991234567"
                                    icon="i-lucide-phone"
                                    required
                                    size="xl"
                                    class="w-full"
                                    @update:model-value="
                                        state.telefono = normalizarTelefono(
                                            String($event),
                                        )
                                    "
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="Email"
                            name="email"
                            required
                            :error="errors.email"
                        >
                            <template #default="{ fieldAttrs }">
                                <UInput
                                    v-bind="fieldAttrs"
                                    v-model="state.email"
                                    type="email"
                                    autocomplete="email"
                                    maxlength="254"
                                    required
                                    placeholder="cliente@ejemplo.com"
                                    icon="i-lucide-mail"
                                    size="xl"
                                    class="w-full"
                                />
                            </template>
                        </FormField>
                    </div>

                    <!-- Botones -->
                    <div
                        class="flex flex-col-reverse gap-3 pt-4 sm:flex-row sm:justify-end"
                    >
                        <UButton
                            type="button"
                            color="neutral"
                            variant="outline"
                            label="Cancelar"
                            @click="handleCancel"
                            :disabled="isLoading"
                        />
                        <UButton
                            type="submit"
                            color="primary"
                            label="Guardar Cliente"
                            icon="i-lucide-save"
                            :loading="isLoading"
                        />
                    </div>
                </form>
            </div>
        </template>
    </UDashboardPanel>
</template>
