import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FormRating from '@/components/FormRating.vue'

describe('FormRating', () => {
  it('affiche 5 étoiles et propage la valeur sur clic', async () => {
    const wrapper = mount(FormRating, { props: { modelValue: 0 } })

    const stars = wrapper.findAll('.star')
    expect(stars).toHaveLength(5)

    await stars[2].trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([3])
  })

  it('affiche le bouton × uniquement si une valeur est saisie', async () => {
    expect(
      mount(FormRating, { props: { modelValue: 0 } })
        .find('.clear')
        .exists(),
    ).toBe(false)

    const wrapper = mount(FormRating, { props: { modelValue: 3 } })
    expect(wrapper.find('.clear').exists()).toBe(true)

    await wrapper.find('.clear').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([0])
  })
})
