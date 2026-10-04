import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import DropBox from '@/components/DropBox.vue'

function setFiles(wrapper: ReturnType<typeof mount>, files: File[]): void {
  Object.defineProperty(wrapper.find('input[type="file"]').element, 'files', {
    value: files,
    configurable: true,
  })
}

describe('DropBox', () => {
  it('émet file-dropped pour un fichier .zip', async () => {
    const wrapper = mount(DropBox)
    const input = wrapper.find('input[type="file"]')

    expect(input.attributes('accept')).toBe('.zip')

    setFiles(wrapper, [new File(['zip'], 'mon-livre.zip', { type: 'application/zip' })])
    await input.trigger('change')

    expect(wrapper.emitted('file-dropped')?.at(-1)?.[0]).toMatchObject({
      name: 'mon-livre.zip',
    })
    expect(wrapper.find('.error').exists()).toBe(false)
  })

  it('rejette un fichier non .zip et affiche le message', async () => {
    const wrapper = mount(DropBox)
    const input = wrapper.find('input[type="file"]')

    setFiles(wrapper, [new File(['rar'], 'livre.rar', { type: 'application/vnd.rar' })])
    await input.trigger('change')

    expect(wrapper.emitted('file-dropped')).toBeUndefined()
    expect(wrapper.find('.error').text()).toBe('Seuls les fichiers .zip sont acceptés.')
  })
})
